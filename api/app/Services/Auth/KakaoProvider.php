<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 카카오 로그인 (웹, 인가 코드 방식)
 *
 *  1) 앱이 카카오 로그인 화면으로 보냄 → 사용자가 동의
 *  2) 카카오가 우리 주소(/auth/kakao?code=...)로 돌려보냄
 *  3) 앱이 code를 서버로 전달 → 여기서 code를 access_token으로 교환 (client_secret은 서버에만)
 *  4) access_token으로 카카오 회원번호 조회 → 우리 서비스의 '카카오 계정 ID'
 *
 * code는 한 번만 쓸 수 있고 몇 분 안에 만료되므로, 중간에 가로채도 재사용이 어렵다.
 */
class KakaoProvider implements SocialProvider
{
    public function verify(string $code): ?string
    {
        $token = Http::asForm()->timeout(5)->post('https://kauth.kakao.com/oauth/token', array_filter([
            'grant_type' => 'authorization_code',
            'client_id' => config('services.kakao.rest_key'),
            'redirect_uri' => config('services.kakao.redirect_uri'),   // 카카오 콘솔에 등록한 주소와 글자까지 같아야 함
            'code' => $code,
            'client_secret' => config('services.kakao.client_secret'), // 콘솔에서 '사용함'일 때만 필요
        ]));

        $accessToken = $token->json('access_token');
        if (! $token->successful() || ! $accessToken) {
            // 흔한 원인: redirect_uri 불일치(KOE006), 이미 쓴 code(KOE320) — 원인 파악용으로 코드만 기록
            Log::warning('kakao token exchange failed', ['status' => $token->status(), 'error' => $token->json('error_code')]);

            return null;
        }

        $me = Http::withToken($accessToken)->timeout(5)->get('https://kapi.kakao.com/v2/user/me');

        return $me->successful() && $me->json('id') ? (string) $me->json('id') : null;
    }
}
