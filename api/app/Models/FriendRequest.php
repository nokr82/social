<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FriendRequest extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const TTL_DAYS = 7;

    protected $attributes = ['status' => self::PENDING];

    protected $fillable = ['from_id', 'to_id', 'status', 'message', 'plaza_id', 'expires_at', 'responded_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_id');
    }

    public function plaza(): BelongsTo
    {
        return $this->belongsTo(Plaza::class)->withTrashed();
    }

    /** 만료되지 않은 요청 (7일 자동 만료는 크론 없이 조회 조건으로 처리) */
    public function scopeAlive(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now());
    }
}
