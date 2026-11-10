<?php

namespace App\Services\Auth;

/**
 * 개발·테스트용. 토큰 "fake:아무ID" → "아무ID"로 로그인.
 * SOCIAL_LOGIN_DRIVER=fake 이고 운영환경이 아닐 때만 바인딩된다.
 */
class FakeSocialProvider implements SocialProvider
{
    public function verify(string $token): ?string
    {
        return str_starts_with($token, 'fake:') && strlen($token) > 5 ? substr($token, 5) : null;
    }
}
