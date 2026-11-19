<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Meetup extends Model
{
    public const OPEN = 'open';
    public const CANCELED = 'canceled';

    public const ROOM_HOURS = 24;     // 채팅방은 약속 24시간 뒤 닫힘
    public const FREE_CANCEL_HOURS = 1; // 1시간 전까지 자유 취소

    protected $attributes = ['status' => self::OPEN, 'age_rule' => 'any'];

    protected $fillable = [
        'plaza_id', 'host_id', 'title', 'description', 'area_label', 'place_detail',
        'place_name', 'place_address', 'place_lat', 'place_lng', 'place_kakao_id',
        'starts_at', 'capacity', 'age_rule', 'age_min', 'age_max', 'status', 'canceled_at', 'settled_at',
    ];

    // 정확한 장소(메모·고른 장소)는 응답에서 기본 제외 (참여자에게만 따로)
    protected $hidden = ['place_detail', 'place_name', 'place_address', 'place_lat', 'place_lng', 'place_kakao_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'canceled_at' => 'datetime', 'settled_at' => 'datetime',
            'place_lat' => 'float', 'place_lng' => 'float'];
    }

    /**
     * 참여자용 장소 정보 (카카오 검색으로 고른 경우만, 직접 입력이면 null).
     * 지도 링크는 카카오 장소 페이지(길찾기 포함) → 지도 SDK·JS 키 쿼터를 쓰지 않음
     */
    public function placeInfo(): ?array
    {
        if ($this->place_name === null) {
            return null;
        }
        $mapUrl = $this->place_kakao_id
            ? 'https://place.map.kakao.com/'.$this->place_kakao_id
            : 'https://map.kakao.com/link/map/'.rawurlencode($this->place_name).','.$this->place_lat.','.$this->place_lng;

        return [
            'name' => $this->place_name,
            'address' => $this->place_address,
            'lat' => $this->place_lat,
            'lng' => $this->place_lng,
            'map_url' => $mapUrl,                 // 웹 주소 (앱이 없을 때·PC)
            'kakao_id' => $this->place_kakao_id,  // 있으면 화면이 카카오맵 앱의 장소 상세로 바로 엶
        ];
    }

    public function plaza(): BelongsTo
    {
        return $this->belongsTo(Plaza::class)->withTrashed();
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(MeetupMember::class);
    }

    /** 참여 확정 인원 (호스트 포함) */
    public function joined(): HasMany
    {
        return $this->hasMany(MeetupMember::class)->where('status', MeetupMember::JOINED);
    }

    public function chatRoom(): HasOne
    {
        return $this->hasOne(ChatRoom::class, 'ref_id')->where('type', ChatRoom::TYPE_MEETUP);
    }

    public function closesAt()
    {
        return $this->starts_at->copy()->addHours(self::ROOM_HOURS);
    }

    /** 채팅방이 열려 있는지 (취소되지 않았고 약속 24시간이 안 지남) */
    public function isRoomOpen(): bool
    {
        return $this->status === self::OPEN && now()->lt($this->closesAt());
    }

    /** 아직 시작 전이라 참여할 수 있는지 */
    public function isUpcoming(): bool
    {
        return $this->status === self::OPEN && now()->lt($this->starts_at);
    }

    /**
     * 조건 문구. '내 나이 ±5세'를 실제 범위로 보여주면 가운데 값이 곧 호스트 나이라
     * (예: 25~35세 → 30세) 나이 비공개 원칙이 깨진다. 그래서 범위 대신 설명으로.
     * 앱 상세와 공유 미리보기가 같은 문구를 쓰도록 모델에 둔다.
     */
    public function conditionLabel(): string
    {
        return match ($this->age_rule) {
            '20s' => '본인인증 · 20대',
            '30s' => '본인인증 · 30대',
            'near' => '본인인증 · 호스트와 비슷한 나이 (±5세)',
            default => '본인인증 회원 누구나',
        };
    }

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->where('status', self::OPEN)->where('starts_at', '>', now());
    }
}
