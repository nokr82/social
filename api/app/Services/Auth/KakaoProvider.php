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
 * 실패하면 원인 파악용으로 카카오 오류 코드(KOE...)와 설명을 로그에 남긴다 (토큰·code 값은 남기지 않음).
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
            // 흔한 원인: KOE006 redirect_uri 불일치 · KOE010 client_secret 불일치 · KOE320 이미 쓴 code
            Log::warning('kakao token exchange failed', [
                'status' => $token->status(),
                'error_code' => $token->json('error_code'),
                'error' => $token->json('error'),
                'description' => $token->json('error_description'),
                'redirect_uri' => config('services.kakao.redirect_uri'),
                'client_secret_set' => filled(config('services.kakao.client_secret')),
            ]);

            return null;
        }

        $me = Http::withToken($accessToken)->timeout(5)->get('https://kapi.kakao.com/v2/user/me');

        if (! $me->successful() || ! $me->json('id')) {
            Log::warning('kakao user/me failed', ['status' => $me->status(), 'code' => $me->json('code'), 'msg' => $me->json('msg')]);

            return null;
        }

        return (string) $me->json('id');
    }
}
