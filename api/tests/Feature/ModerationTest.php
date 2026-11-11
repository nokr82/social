<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BannedIdentity;
use App\Models\Block;
use App\Models\MeetupMember;
use App\Models\Report;
use App\Models\User;
use App\Models\UserSanction;
use App\Services\Moderation\ModerationService;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 운영 묶음: 신고 처리·정지·영구정지, 번개 출석 정산, 차단 목록 */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private ModerationService $mod;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mod = app(ModerationService::class);
        $this->admin = Admin::create(['name' => '운영자', 'email' => 'ops@test.local', 'password' => 'password-123456']);
    }

    private function report(User $from, User $to): Report
    {
        $this->actingAs($from)->postJson('/api/reports', ['user_id' => $to->id, 'target_type' => 'user', 'reason' => 'abuse', 'block' => false])->assertCreated();

        return Report::where('reporter_id', $from->id)->where('target_id', $to->id)->firstOrFail();
    }

    public function test_admin_password_is_hashed(): void
    {
        $this->assertNotSame('password-123456', $this->admin->getRawOriginal('password'));
    }

    /** 승인 + 7일 정지 → 상태·기간·이력·감점·토큰 폐기 */
    public function test_accept_with_suspend(): void
    {
        [$reporter, $target] = User::factory()->count(2)->complete()->create();
        $target->createToken('app');
        $report = $this->report($reporter, $target);

        $this->mod->resolve($this->admin, $report, true, 'suspend', 7, '욕설 확인');

        $target->refresh();
        $this->assertSame('suspended', $target->status);
        $this->assertTrue($target->suspended_until->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(500 - config('social.trust.report_penalty'), $target->trustScore->score);
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'accepted', 'action' => 'suspend', 'handled_by' => $this->admin->id]);
        $this->assertDatabaseHas('user_sanctions', ['user_id' => $target->id, 'type' => 'suspend', 'admin_id' => $this->admin->id]);

        // 같은 신고 두 번 처리 불가
        $this->expectExceptionMessage('이미 처리된 신고예요');
        $this->mod->resolve($this->admin, $report->fresh(), true);
    }

    /** 정지 기간이 지나면 다음 요청에서 자동 해제 */
    public function test_suspension_expires_on_next_request(): void
    {
        $user = User::factory()->complete()->create();
        $this->mod->suspend($user, $this->admin, 1);

        // /me는 정지 중에도 열려 있음 (앱이 정지 안내를 띄우려고) → 상태만 suspended
        $this->actingAs($user->fresh())->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspended_until', fn ($v) => $v !== null);
        // 실제 기능 경로는 403
        $this->actingAs($user->fresh())->getJson('/api/plazas')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        // 기간이 끝나면 /me만 불러도 풀림 (기능 경로를 먼저 안 불러도 정확한 상태)
        $this->travelTo(now()->addDays(1)->addMinute());
        $this->actingAs($user->fresh())->getJson('/api/me')->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame('active', $user->fresh()->status);
        $this->assertDatabaseHas('user_sanctions', ['user_id' => $user->id, 'type' => 'unsuspend', 'admin_id' => null]);
    }

    /** 영구정지 → CI가 차단 명단에 올라 재가입 불가 */
    public function test_ban_registers_ci(): void
    {
        $user = User::factory()->complete()->create();
        $this->mod->ban($user, $this->admin, '사기');

        $this->assertSame('banned', $user->fresh()->status);
        $this->assertTrue(BannedIdentity::whereKey($user->identity->getRawOriginal('ci_hash'))->exists());
    }

    /** 자동 정지는 시스템 이력으로 남고, 신고가 반려돼 기준 아래로 떨어지면 풀린다 */
    public function test_auto_suspend_released_when_reports_rejected(): void
    {
        $target = User::factory()->complete()->create();
        $reporters = User::factory()->count(config('social.trust.auto_suspend_reports'))->complete()->create();
        $reports = $reporters->map(fn ($r) => $this->report($r, $target));

        $this->assertSame('suspended', $target->fresh()->status);
        $this->assertDatabaseHas('user_sanctions', ['user_id' => $target->id, 'type' => 'suspend', 'admin_id' => null]);

        $this->mod->resolve($this->admin, $reports->first(), false, note: '허위 신고');

        $this->assertSame('active', $target->fresh()->status);
        $this->assertSame(500, $target->trustScore->fresh()->score); // 반려는 감점 없음
    }

    /** 관리자가 직접 정지한 사람은 신고 반려로 풀리지 않음 */
    public function test_manual_suspend_not_released_by_reject(): void
    {
        [$reporter, $target] = User::factory()->count(2)->complete()->create();
        $report = $this->report($reporter, $target);
        $this->mod->suspend($target, $this->admin, 3);

        $this->mod->resolve($this->admin, $report, false);

        $this->assertSame('suspended', $target->fresh()->status);
    }

    /** 끝난 번개만 한 번 정산: 참석자 +1·가점, 노쇼·취소자는 제외 */
    public function test_settle_meetups(): void
    {
        $host = User::factory()->complete()->create();
        [$guest, $noshow, $quit] = User::factory()->count(3)->complete()->create();
        $plaza = app(PlazaService::class)->create($host, ['name' => '역삼 수다방', 'category' => 'talk'], 37.5006, 127.0364);
        $id = $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/meetups", [
            'title' => '한잔', 'area_label' => '역삼', 'place_detail' => 'OO호프',
            'starts_at' => now()->addHours(3)->toIso8601String(), 'capacity' => 5, 'age_rule' => 'any',
        ])->assertCreated()->json('data.id');
        foreach ([$guest, $noshow, $quit] as $u) {
            $this->actingAs($u)->postJson("/api/meetups/{$id}/join")->assertOk();
        }
        $this->actingAs($quit)->postJson("/api/meetups/{$id}/cancel")->assertOk();
        MeetupMember::where('meetup_id', $id)->where('user_id', $noshow->id)->update(['noshow_at' => now()]);

        // 아직 채팅방이 열려 있으면 정산하지 않음
        $this->artisan('meetups:settle')->assertSuccessful();
        $this->assertNull(\App\Models\Meetup::find($id)->settled_at);

        $this->travelTo(now()->addHours(3 + 24)->addMinute());
        $this->artisan('meetups:settle')->assertSuccessful();
        $this->artisan('meetups:settle')->assertSuccessful(); // 두 번 실행해도 한 번만 반영

        $bonus = config('social.trust.attend_bonus');
        foreach ([$host, $guest] as $u) {
            $this->assertSame(1, $u->trustScore->fresh()->attend_count);
            $this->assertSame(500 + $bonus, $u->trustScore->fresh()->score);
        }
        foreach ([$noshow, $quit] as $u) {
            $this->assertSame(0, $u->trustScore->fresh()->attend_count);
        }
        $this->assertNotNull(\App\Models\Meetup::find($id)->settled_at);
    }

    /** 차단 목록: 내가 차단한 사람만, 나를 차단한 사람은 안 보임 */
    public function test_my_block_list(): void
    {
        [$me, $a, $b] = User::factory()->count(3)->complete()->create();
        $this->actingAs($me)->postJson('/api/blocks', ['user_id' => $a->id])->assertOk();
        $this->actingAs($b)->postJson('/api/blocks', ['user_id' => $me->id])->assertOk();

        $this->actingAs($me)->getJson('/api/blocks/mine')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $a->id)
            ->assertJsonPath('data.0.user.nickname', $a->nickname);

        $this->actingAs($me)->deleteJson("/api/blocks/{$a->id}")->assertOk();
        $this->actingAs($me)->getJson('/api/blocks/mine')->assertJsonCount(0, 'data');
        $this->assertSame(1, Block::count());
    }
}
