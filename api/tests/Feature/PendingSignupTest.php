<?php

namespace Tests\Feature;

use App\Models\BannedIdentity;
use App\Models\PendingSignup;
use App\Services\Auth\SignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 본인인증 후 가입을 마치기 전에 나갔다 와도 인증을 다시 하지 않는다 */
class PendingSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.drivers.identity' => 'self']);
    }

    private function social(string $id = 'fake:resume-1')
    {
        return $this->postJson('/api/auth/social', ['provider' => 'kakao', 'token' => $id])->assertOk();
    }

    private function verify(string $token)
    {
        return $this->postJson('/api/auth/identity', [
            'signup_token' => $token, 'gender' => 'M', 'birth_date' => '1995-03-03', 'adult' => true,
        ])->assertOk();
    }

    public function test_resume_after_leaving_skips_identity(): void
    {
        // 처음: 인증 필요
        $first = $this->social()->assertJsonPath('identity', null);
        $this->verify($first->json('signup_token'));

        // 닉네임 전에 나갔다가 다시 로그인 → 인증 결과가 이미 붙어 있음
        $again = $this->social()
            ->assertJsonPath('status', 'signup')
            ->assertJsonPath('identity.gender', 'M')
            ->assertJsonPath('identity.verified', false);

        // 인증 없이 바로 가입 완료 가능
        $this->postJson('/api/auth/register', ['signup_token' => $again->json('signup_token'), 'nickname' => '이어하기'])
            ->assertCreated();

        $this->assertSame(0, PendingSignup::count());             // 가입 완료 시 보관분 삭제
        $this->social()->assertJsonPath('status', 'login');      // 이후엔 그냥 로그인
    }

    public function test_pending_identity_is_encrypted_and_expires(): void
    {
        $this->verify($this->social()->json('signup_token'));

        // 성별·생년월일은 DB에 평문으로 남지 않음
        $raw = \DB::table('pending_signups')->value('identity');
        $this->assertStringNotContainsString('1995-03-03', $raw);

        // 7일 지나면 다시 인증
        $this->travel(PendingSignup::KEEP_DAYS + 1)->days();
        $this->social()->assertJsonPath('identity', null);
        $this->artisan('model:prune', ['--model' => [PendingSignup::class]])->assertSuccessful();
        $this->assertSame(0, PendingSignup::count());
    }

    /** 보관 중에 영구정지되면 이어하기 불가 → 인증 단계에서 차단 */
    public function test_banned_while_pending(): void
    {
        $this->verify($this->social()->json('signup_token'));
        BannedIdentity::create(['ci_hash' => SignupService::hashCi('self:kakao:resume-1'), 'reason' => 't']);

        $token = $this->social()->assertJsonPath('identity', null)->json('signup_token');
        $this->postJson('/api/auth/identity', [
            'signup_token' => $token, 'gender' => 'M', 'birth_date' => '1995-03-03', 'adult' => true,
        ])->assertForbidden()->assertJson(['code' => 'BANNED']);
    }
}
