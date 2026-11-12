<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PASS 본인인증 결과. 가입 시 1회 생성되고 이후 수정하지 않는다.
 */
class UserIdentity extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['user_id', 'ci_hash', 'gender', 'birth_date', 'provider', 'verified_at'];

    // 어떤 API 응답에도 ci_hash가 섞여 나가지 않도록
    protected $hidden = ['ci_hash'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 대행사(PASS 등) 인증이면 true, 베타 직접 입력이면 false → 화면에 '인증 전' */
    public function isVerified(): bool
    {
        return ! in_array($this->provider, ['self'], true);
    }

    /** 만 나이 */
    public function age(): int
    {
        return $this->birth_date->age;
    }
}
