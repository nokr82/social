<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;

/**
 * 카카오: 앱이 카카오 SDK로 받은 access_token을 카카오 API로 조회해 검증.
 */
class KakaoProvider implements SocialProvider
{
    public function verify(string $token): ?string
    {
        $res = Http::withToken($token)->timeout(5)->get('https://kapi.kakao.com/v2/user/me');

        return $res->successful() && $res->json('id') ? (string) $res->json('id') : null;
    }
}
