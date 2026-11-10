<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 애플: 앱이 받은 identity_token(JWT)을 애플 공개키로 서명 검증.
 * - iss가 애플인지, aud가 우리 앱 번들 ID인지 확인
 * - sub가 애플 고유 사용자 ID
 */
class AppleProvider implements SocialProvider
{
    public function verify(string $token): ?string
    {
        try {
            // 애플 공개키는 자주 안 바뀌므로 1시간 캐시
            $jwks = Cache::remember('apple:jwks', 3600, fn () => Http::timeout(5)
                ->get('https://appleid.apple.com/auth/keys')->throw()->json());

            $payload = JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
        } catch (Throwable) {
            return null; // 서명 불일치·만료·키 조회 실패 모두 로그인 거부
        }

        $validIssuer = ($payload->iss ?? null) === 'https://appleid.apple.com';
        $validAudience = ($payload->aud ?? null) === config('services.apple.client_id');

        return $validIssuer && $validAudience && ! empty($payload->sub) ? (string) $payload->sub : null;
    }
}
