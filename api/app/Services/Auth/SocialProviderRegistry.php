<?php

namespace App\Services\Auth;

use InvalidArgumentException;

/**
 * provider 이름 → 검증기. SOCIAL_LOGIN_DRIVER=fake면 전부 FakeSocialProvider.
 */
class SocialProviderRegistry
{
    public function get(string $provider): SocialProvider
    {
        if (config('social.drivers.social') === 'fake') {
            return app(FakeSocialProvider::class);
        }

        return match ($provider) {
            'kakao' => app(KakaoProvider::class),
            'apple' => app(AppleProvider::class),
            default => throw new InvalidArgumentException("unknown provider: {$provider}"),
        };
    }
}
