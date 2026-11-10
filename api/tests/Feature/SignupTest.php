<?php

namespace Tests\Feature;

use App\Models\BannedIdentity;
use App\Models\User;
use App\Services\Auth\SignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    /** 소셜 → PASS → 닉네임 순서로 가입하고, 같은 소셜로 다시 오면 로그인 */
    public function test_full_signup_then_login(): void
    {
        $signupToken = $this->postJson('/api/auth/social', ['provider' => 'kakao', 'token' => 'fake:k1'])
            ->assertOk()->assertJson(['status' => 'signup'])->json('signup_token');

        $this->postJson('/api/auth/identity', ['signup_token' => $signupToken, 'transaction_id' => 'fake:ci-1:F:19980312'])
            ->assertOk()
            ->assertJson(['gender' => 'F'])          // 인증 완료 카드에 표시
            ->assertJsonStructure(['age']);

        $this->postJson('/api/auth/register', [
            'signup_token' => $signupToken, 'nickname' => '똥우',
            'avatar' => ['color' => 'mint', 'face' => 'grin'],
        ])
            ->assertCreated()
            ->assertJsonPath('user.nickname', '똥우')
            ->assertJsonPath('user.gender', 'F')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.avatar_version', 1)
            ->assertJsonPath('user.avatar.color', 'mint');

        $user = User::firstWhere('nickname', '똥우');
        $this->assertNotNull($user->identity);
        $this->assertNotSame('ci-1', $user->identity->getRawOriginal('ci_hash')); // 원문 저장 안 함
        $this->assertSame(500, (int) $user->trustScore->fresh()->score);

        $this->postJson('/api/auth/social', ['provider' => 'kakao', 'token' => 'fake:k1'])
            ->assertOk()->assertJson(['status' => 'login'])->assertJsonStructure(['token']);
    }

    public function test_under_19_is_rejected(): void
    {
        $token = $this->postJson('/api/auth/social', ['provider' => 'kakao', 'token' => 'fake:k2'])->json('signup_token');
        $birth = now()->subYears(18)->format('Ymd');

        $this->postJson('/api/auth/identity', ['signup_token' => $token, 'transaction_id' => "fake:ci-2:M:{$birth}"])
            ->assertForbidden()->assertJson(['code' => 'UNDER_AGE']);
    }

    /** 같은 사람(CI)이 다른 소셜 계정으로 또 가입하면 차단 */
    public function test_duplicate_ci_is_rejected(): void
    {
        foreach (['fake:a', 'fake:b'] as $i => $social) {
            $token = $this->postJson('/api/auth/social', ['provider' => 'kakao', 'token' => $social])->json('signup_token');
            $res = $this->postJson('/api/auth/identity', ['signup_token' => $token, 'transaction_id' => 'fake:same-ci:M:19900101']);

            if ($i === 0) {
                $this->postJson('/api/auth/register', ['signup_token' => $token, 'nickname' => 'first'])->assertCreated();
            } else {
                $res->assertStatus(409)->assertJson(['code' => 'ALREADY_REGISTERED']);
            }
        }
    }

    public function test_banned_ci_cannot_signup(): void
    {
        BannedIdentity::create(['ci_hash' => SignupService::hashCi('bad-ci')]);
        $token = $this->postJson('/api/auth/social', ['provider' => 'kakao', 'token' => 'fake:c'])->json('signup_token');

        $this->postJson('/api/auth/identity', ['signup_token' => $token, 'transaction_id' => 'fake:bad-ci:M:19900101'])
            ->assertForbidden()->assertJson(['code' => 'BANNED']);
    }

    public function test_register_requires_identity(): void
    {
        $token = $this->postJson('/api/auth/social', ['provider' => 'kakao', 'token' => 'fake:d'])->json('signup_token');

        $this->postJson('/api/auth/register', ['signup_token' => $token, 'nickname' => 'nopass'])
            ->assertStatus(422)->assertJson(['code' => 'IDENTITY_REQUIRED']);
    }

    public function test_suspended_user_cannot_use_plazas(): void
    {
        $user = User::factory()->suspended()->complete()->create();

        $this->actingAs($user)->getJson('/api/plazas?lat=37.5&lng=127.03')->assertForbidden();
        $this->actingAs($user)->getJson('/api/me')->assertOk();
    }
}
