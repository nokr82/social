<?php

namespace Tests\Feature;

use App\Models\BannedIdentity;
use App\Models\User;
use App\Services\Auth\KakaoProvider;
use App\Services\Auth\SignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 출시 모드: 카카오 인가 코드 로그인 + 베타 직접 입력 본인인증 */
class LaunchAuthTest extends TestCase
{
    use RefreshDatabase;

    private function startSignup(string $social = 'fake:beta1'): string
    {
        return $this->postJson('/api/auth/social', ['provider' => 'kakao', 'token' => $social])->json('signup_token');
    }

    private function selfVerify(string $token, array $over = [])
    {
        return $this->postJson('/api/auth/identity', [
            'signup_token' => $token, 'gender' => 'F', 'birth_date' => '1997-05-05', 'adult' => true, ...$over,
        ]);
    }

    public function test_self_declared_signup_is_marked_unverified(): void
    {
        config(['social.drivers.identity' => 'self']);
        $token = $this->startSignup();

        $this->selfVerify($token)->assertOk()->assertJson(['gender' => 'F', 'verified' => false]);
        $this->postJson('/api/auth/register', ['signup_token' => $token, 'nickname' => '베타유저'])
            ->assertCreated()
            ->assertJsonPath('user.identity_verified', false);

        $user = User::firstWhere('nickname', '베타유저');
        $this->assertSame('self', $user->identity->provider);
        // 다른 사람이 프로필을 볼 때도 '인증 전'
        $other = User::factory()->complete()->create();
        $this->actingAs($other)->getJson("/api/users/{$user->id}")->assertJsonPath('data.identity_verified', false);
    }

    public function test_self_declared_requires_adult_check_and_age(): void
    {
        config(['social.drivers.identity' => 'self']);
        $token = $this->startSignup();

        $this->selfVerify($token, ['adult' => false])->assertUnprocessable()->assertJsonValidationErrors('adult');
        $this->selfVerify($token, ['birth_date' => now()->subYears(18)->toDateString()])
            ->assertForbidden()->assertJson(['code' => 'UNDER_AGE']);
    }

    /** 베타에서는 카카오 계정이 'CI' 역할: 영구정지된 카카오 계정은 다시 가입 못 함 */
    public function test_ban_works_per_kakao_account_in_beta(): void
    {
        config(['social.drivers.identity' => 'self']);
        BannedIdentity::create(['ci_hash' => SignupService::hashCi('self:kakao:beta-banned'), 'reason' => 't']);

        $this->selfVerify($this->startSignup('fake:beta-banned'))->assertForbidden()->assertJson(['code' => 'BANNED']);
        $this->selfVerify($this->startSignup('fake:beta-ok'))->assertOk();
    }

    public function test_meta_auth_tells_client_what_to_show(): void
    {
        config(['social.drivers.identity' => 'self', 'services.kakao.rest_key' => 'rk', 'services.kakao.redirect_uri' => 'https://social.devhong.shop/auth/kakao']);

        $this->getJson('/api/meta/auth')->assertOk()->assertJson([
            'identity' => 'self',
            'kakao' => ['client_id' => 'rk', 'redirect_uri' => 'https://social.devhong.shop/auth/kakao'],
        ]);
    }

    /** 카카오: code → access_token → 회원번호. 실패하면 null */
    public function test_kakao_code_exchange(): void
    {
        config(['services.kakao.rest_key' => 'rk', 'services.kakao.redirect_uri' => 'https://social.devhong.shop/auth/kakao']);
        Http::fake([
            'kauth.kakao.com/oauth/token' => Http::sequence()
                ->push(['access_token' => 'at-1'])
                ->push(['error' => 'invalid_grant', 'error_code' => 'KOE320'], 400),
            'kapi.kakao.com/v2/user/me' => Http::response(['id' => 12345]),
        ]);

        $this->assertSame('12345', app(KakaoProvider::class)->verify('code-1'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth/token')
            && $r['code'] === 'code-1' && $r['redirect_uri'] === 'https://social.devhong.shop/auth/kakao');

        $this->assertNull(app(KakaoProvider::class)->verify('used-code')); // 이미 쓴 code
    }
}
