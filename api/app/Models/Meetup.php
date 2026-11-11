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
    public const MAX_AHEAD_HOURS = 48; // 48시간 이내 약속만
    public const FREE_CANCEL_HOURS = 1; // 1시간 전까지 자유 취소

    protected $attributes = ['status' => self::OPEN, 'age_rule' => 'any'];

    protected $fillable = [
        'plaza_id', 'host_id', 'title', 'description', 'area_label', 'place_detail',
        'starts_at', 'capacity', 'age_rule', 'age_min', 'age_max', 'status', 'canceled_at', 'settled_at',
    ];

    protected $hidden = ['place_detail']; // 정확한 장소는 응답에서 기본 제외 (참여자에게만 따로)

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'canceled_at' => 'datetime', 'settled_at' => 'datetime'];
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

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->where('status', self::OPEN)->where('starts_at', '>', now());
    }
}
