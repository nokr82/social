<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * 관리자 계정. 앱 사용자(User)와 테이블·가드를 분리해
 * 앱 토큰으로는 관리자 기능에 절대 접근할 수 없게 한다.
 */
class Admin extends Authenticatable implements FilamentUser
{
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /**
     * Filament 접근 허용. 이 인터페이스가 없으면 운영(APP_ENV≠local)에서 패널이 막히고,
     * 개발에서는 로그인한 누구나 들어올 수 있어 반드시 구현해야 한다.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin';
    }
}
