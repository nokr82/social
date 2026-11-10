<?php

namespace App\Services\Auth;

/**
 * 소셜 로그인 검증기. 앱이 받은 토큰을 서버가 다시 검증해 고유 ID를 얻는다.
 * (앱이 보낸 사용자 ID를 그대로 믿으면 남의 계정으로 로그인할 수 있음)
 */
interface SocialProvider
{
    /** 토큰이 유효하면 플랫폼 고유 사용자 ID, 아니면 null */
    public function verify(string $token): ?string;
}
