<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 서명 URL을 15분 구간마다 같게 만든다 (브라우저 캐시가 먹히게).
 *
 * 왜: 만료 시각을 now()+30분으로 두면 1초마다 서명이 달라져 URL이 매번 바뀐다.
 *     브라우저는 URL이 다르면 다른 파일로 보므로 Cache-Control을 줘도 사진을 매번 새로 받았다(2026-11-20 발견).
 * 방법: 만료 = (지금이 속한 15분 구간의 끝) + 30분 → 같은 구간 안의 요청은 같은 만료·같은 서명.
 *       남은 유효시간은 늘 30~45분(전과 거의 같음).
 * S3/R2 임시 URL은 서명 시각(X-Amz-Date)이 들어가 만료를 맞춰도 매번 달라지므로, 만든 URL 자체를 구간 끝까지 캐시한다.
 */
final class StableUrl
{
    public const WINDOW_SECONDS = 900; // 15분

    /** 지금 구간에 쓸 만료 시각 */
    public static function expiresAt(int $validMinutes = 30): CarbonImmutable
    {
        return self::windowEnd()->addMinutes($validMinutes);
    }

    /**
     * 같은 구간 동안 같은 URL을 돌려준다. $make(만료 시각)로 URL을 만들고 구간 끝까지 캐시.
     * 키에 구간 번호가 들어가므로 구간이 바뀌면 자연히 새 URL.
     */
    public static function remember(string $key, Closure $make, int $validMinutes = 30): string
    {
        $window = intdiv(now()->getTimestamp(), self::WINDOW_SECONDS);

        return Cache::remember(
            "stable-url:{$key}:{$window}",
            self::windowEnd(),
            fn () => $make(self::expiresAt($validMinutes)),
        );
    }

    private static function windowEnd(): CarbonImmutable
    {
        $window = intdiv(now()->getTimestamp(), self::WINDOW_SECONDS);

        return CarbonImmutable::createFromTimestamp(($window + 1) * self::WINDOW_SECONDS, now()->getTimezone());
    }
}
