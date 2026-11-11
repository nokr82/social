<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 방장 강퇴 기록. 방장이 해제(revoked_at)하기 전까지 그 광장에 다시 못 들어온다.
 * 해제해도 행은 남긴다 (하루 강퇴 한도 계산·분쟁 확인용, 오래되면 plazas:purge가 정리).
 */
class PlazaKick extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['plaza_id', 'user_id', 'kicked_by', 'reason', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** 아직 해제되지 않은 강퇴 */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }

    /** 강퇴당한 사람 */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
