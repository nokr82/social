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

    // DB 기본값과 같게: create() 직후 인스턴스에도 값이 채워지도록
    // (DB DEFAULT는 INSERT 후 모델에 다시 읽혀 오지 않는다)
    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected $fillable = [
        'host_id', 'name', 'description', 'category',
        'region_cell', 'lat', 'lng', 'capacity', 'status', 'last_message_at', 'last_message_body',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'last_message_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function chatRoom(): HasOne
    {
        return $this->hasOne(ChatRoom::class, 'ref_id')->where('type', ChatRoom::TYPE_PLAZA);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }
}
