<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * 본인인증은 끝났지만 가입(닉네임·아바타)을 마치지 않은 소셜 계정.
 * identity는 성별·생년월일이 들어 있어 DB에 암호화해 저장한다 (APP_KEY로 복호화).
 */
class PendingSignup extends Model
{
    use Prunable;

    public const KEEP_DAYS = 7;

    protected $fillable = ['provider', 'provider_user_id', 'identity', 'expires_at'];

    protected function casts(): array
    {
        return [
            'identity' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }

    /** 유효한(만료 전) 것만 */
    public function scopeAlive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /** php artisan model:prune 이 지울 대상: 만료된 것 */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }
}
