<?php

namespace App\Providers;

use App\Services\Identity\FakeIdentityVerifier;
use App\Services\Identity\IdentityVerifier;
use App\Services\Identity\PassIdentityVerifier;
use App\Services\Plaza\ArrayPlazaPresence;
use App\Services\Plaza\PlazaPresence;
use App\Services\Plaza\RedisPlazaPresence;
use App\Services\Region\FakeRegionResolver;
use App\Services\Region\KakaoRegionResolver;
use App\Services\Region\RegionResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 본인인증: PASS 대행사 계약 전까지 fake
        $this->app->singleton(IdentityVerifier::class, fn () => config('social.drivers.identity') === 'pass'
            ? new PassIdentityVerifier
            : new FakeIdentityVerifier);

        // 광장 접속자: 운영은 Redis, 테스트는 메모리
        $this->app->singleton(PlazaPresence::class, fn () => config('social.drivers.presence') === 'array'
            ? new ArrayPlazaPresence
            : new RedisPlazaPresence);

        // 동네(법정동) 찾기: 운영은 카카오 로컬 API, 개발·테스트는 가짜
        $this->app->singleton(RegionResolver::class, fn () => config('social.drivers.region') === 'kakao'
            ? new KakaoRegionResolver
            : new FakeRegionResolver);
    }

    public function boot(): void
    {
        // 안전장치: 운영환경에서 가짜 로그인·가짜 본인인증이 켜져 있으면 부팅 자체를 막는다
        if ($this->app->isProduction()
            && (config('social.drivers.social') === 'fake' || config('social.drivers.identity') === 'fake')) {
            throw new RuntimeException('운영환경에서 fake 로그인/본인인증 드라이버를 사용할 수 없습니다.');
        }
        // 운영에서 가짜 동네(모두 '역삼동')가 나가면 안 됨
        if ($this->app->isProduction() && config('social.drivers.region') !== 'kakao') {
            throw new RuntimeException('운영환경에서는 REGION_DRIVER=kakao와 KAKAO_REST_API_KEY가 필요합니다.');
        }

        // 채팅 도배 방지: 사용자당 분당 30개
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        // 인증 API 무차별 대입 방지: IP당 분당 20회
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
