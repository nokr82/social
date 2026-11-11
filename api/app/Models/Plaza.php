<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plaza extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public const TYPE_USER = 'user'; // 주민이 만든 광장 (방장 있음, 닫힐 수 있음)
    public const TYPE_TOWN = 'town'; // 동네 상시 광장 (법정동마다 1개, 방장 없음, 닫히지 않음)

    // DB 기본값과 같게: create() 직후 인스턴스에도 값이 채워지도록
    // (DB DEFAULT는 INSERT 후 모델에 다시 읽혀 오지 않는다)
    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'type' => self::TYPE_USER,
    ];

    protected $fillable = [
        'type', 'region_code', 'host_id', 'name', 'description', 'category',
        'region_cell', 'lat', 'lng', 'capacity', 'status', 'last_message_at', 'last_message_body', 'host_seen_at', 'closed_at', 'closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'last_message_at' => 'datetime',
            'host_seen_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isTown(): bool
    {
        return $this->type === self::TYPE_TOWN;
    }

    /** 방장이 유예시간 넘게 자리를 비웠나 (닫기 대상). 상시 광장은 방장이 없으니 해당 없음 */
    public function isHostGone(): bool
    {
        if ($this->isTown()) {
            return false;
        }

        return $this->host_seen_at === null
            || $this->host_seen_at->lt(now()->subSeconds((int) config('social.plaza.host_grace')));
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** 대화방 (상시 광장도 같은 타입의 방 1개, 구역·채널은 chat_messages.channel로 구분) */
    public function chatRoom(): HasOne
    {
        return $this->hasOne(ChatRoom::class, 'ref_id')->where('type', ChatRoom::TYPE_PLAZA);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    /** 주민이 만든 광장만 (상시 광장 제외) */
    public function scopeUserMade(Builder $q): Builder
    {
        return $q->where('type', self::TYPE_USER);
    }

    /** 열려 있고 방장도 유예시간 안에 있던 주민 광장 (목록·개수 제한용) */
    public function scopeAlive(Builder $q): Builder
    {
        return $q->userMade()->open()->where('host_seen_at', '>=', now()->subSeconds((int) config('social.plaza.host_grace')));
    }

    /** 방장이 유예시간 넘게 없는 열린 주민 광장 (정리 명령용) */
    public function scopeHostGone(Builder $q): Builder
    {
        return $q->userMade()->open()->where(fn ($w) => $w
            ->whereNull('host_seen_at')
            ->orWhere('host_seen_at', '<', now()->subSeconds((int) config('social.plaza.host_grace'))));
    }
}
