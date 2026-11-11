<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetupMember extends Model
{
    public const JOINED = 'joined';
    public const CANCELED = 'canceled';
    public const LATE_CANCELED = 'late_canceled';

    public $incrementing = false;
    protected $primaryKey = null; // 복합 PK → 갱신은 쿼리 빌더로
    public $timestamps = false;

    protected $attributes = ['status' => self::JOINED];

    protected $fillable = ['meetup_id', 'user_id', 'status', 'arrived_at', 'noshow_at', 'joined_at', 'canceled_at'];

    protected function casts(): array
    {
        return ['arrived_at' => 'datetime', 'noshow_at' => 'datetime', 'joined_at' => 'datetime', 'canceled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
