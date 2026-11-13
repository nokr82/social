<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 위치 공유 1건: sharer가 viewer에게 expires_at까지 (null = 끌 때까지) */
class LocationShare extends Model
{
    protected $fillable = ['sharer_id', 'viewer_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sharer_id');
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }

    /** 아직 유효한 공유 (만료 행은 지우지 않고 조회에서 거른다) */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** 두 사람 사이의 공유(양방향) */
    public function scopeBetween(Builder $query, int $a, int $b): Builder
    {
        return $query->where(fn ($q) => $q->where('sharer_id', $a)->where('viewer_id', $b))
            ->orWhere(fn ($q) => $q->where('sharer_id', $b)->where('viewer_id', $a));
    }
}
