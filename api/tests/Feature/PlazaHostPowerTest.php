<?php

namespace Tests\Feature;

use App\Events\PlazaClosed;
use App\Events\PlazaHostChanged;
use App\Events\PlazaMemberKicked;
use App\Models\ChatMessage;
use App\Models\Plaza;
use App\Models\PlazaKick;
use App\Models\Report;
use App\Models\User;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** 방장 권한(내보내기·넘기기·닫기), 관리자 닫기, 닫힌 광장 대화 정리 */
class PlazaHostPowerTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 37.5006;
    private const LNG = 127.0364;

    private User $host;
    private Plaza $plaza;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([PlazaClosed::class, PlazaHostChanged::class, PlazaMemberKicked::class]);
        $this->host = User::factory()->complete()->create();
        $this->plaza = app(PlazaService::class)->create($this->host, ['name' => '역삼 수다방', 'category' => 'talk'], self::LAT, self::LNG);
        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/enter")->assertOk();
    }

    private function guest(int $score = 500): User
    {
        $u = User::factory()->complete()->create();
        $u->trustScore()->update(['score' => $score]);
        $this->actingAs($u)->postJson("/api/plazas/{$this->plaza->id}/enter")->assertOk();

        return $u->fresh();
    }

    private function kick(User $by, User $target, string $reason = 'spam')
    {
        return $this->actingAs($by)->postJson("/api/plazas/{$this->plaza->id}/kick", ['user_id' => $target->id, 'reason' => $reason]);
    }

    /* ---------------- 왕관 ---------------- */

    public function test_plaza_exposes_host_id_for_crown(): void
    {
        $guest = $this->guest();
        $this->actingAs($guest)->getJson("/api/plazas/{$this->plaza->id}")
            ->assertJsonPath('data.host_id', $this->host->id)
            ->assertJsonPath('data.is_host', false);
    }

    /* ---------------- 내보내기 ---------------- */

    public function test_kicked_user_stays_out_until_host_revokes(): void
    {
        $guest = $this->guest();

        $kickId = $this->kick($this->host, $guest, 'abuse')->assertOk()->json('kick_id');
        Event::assertDispatched(PlazaMemberKicked::class, fn ($e) => $e->userId === $guest->id);

        // 접속에서 빠짐 → heartbeat는 NOT_IN_PLAZA, 재입장은 KICKED, 메시지도 불가
        $this->actingAs($guest)->postJson("/api/plazas/{$this->plaza->id}/heartbeat")->assertStatus(409);
        $this->actingAs($guest)->postJson("/api/plazas/{$this->plaza->id}/enter")
            ->assertForbidden()->assertJsonPath('code', 'KICKED');
        $this->actingAs($guest)->postJson("/api/plazas/{$this->plaza->id}/messages", ['body' => '나 다시 왔지롱'])
            ->assertForbidden();

        // 목록에서도 숨김 (다른 사람에게는 보임)
        $q = '?lat='.self::LAT.'&lng='.self::LNG;
        $this->actingAs($guest)->getJson("/api/plazas{$q}")->assertJsonCount(0, 'data');
        $this->actingAs($this->host)->getJson("/api/plazas{$q}")->assertJsonCount(1, 'data');

        // 시간이 지나도 풀리지 않음 (기한 없음)
        $this->travel(3)->days();
        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/enter")->assertOk(); // 방장 복귀
        $this->actingAs($guest)->postJson("/api/plazas/{$this->plaza->id}/enter")->assertJsonPath('code', 'KICKED');

        // 방장의 강퇴 목록에 보이고, 해제하면 다시 입장 가능
        $this->actingAs($this->host)->getJson("/api/plazas/{$this->plaza->id}/kicks")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kickId)
            ->assertJsonPath('data.0.user.id', $guest->id)
            ->assertJsonPath('data.0.reason_label', '욕설 · 비방');
        $this->actingAs($this->host)->deleteJson("/api/plazas/{$this->plaza->id}/kicks/{$kickId}")->assertOk();

        $this->actingAs($this->host)->getJson("/api/plazas/{$this->plaza->id}/kicks")->assertJsonCount(0, 'data');
        $this->actingAs($guest)->postJson("/api/plazas/{$this->plaza->id}/enter")->assertOk();
        $this->assertNotNull(PlazaKick::find($kickId)->revoked_at); // 기록은 남음
        $this->assertSame($this->host->id, (int) PlazaKick::find($kickId)->revoked_by);
    }

    /** 강퇴 목록·해제도 지금 방장만. 방장이 바뀌면 새 방장이 이어서 관리 */
    public function test_only_current_host_manages_kick_list(): void
    {
        $guest = $this->guest();
        $next = $this->guest(500);
        $kickId = $this->kick($this->host, $guest)->assertOk()->json('kick_id');

        $this->actingAs($next)->getJson("/api/plazas/{$this->plaza->id}/kicks")->assertForbidden();
        $this->actingAs($next)->deleteJson("/api/plazas/{$this->plaza->id}/kicks/{$kickId}")->assertForbidden();

        // 다른 광장 주소로 해제 시도 → 404
        $other = app(PlazaService::class)->create($this->host, ['name' => '다른 방', 'category' => 'talk'], self::LAT, self::LNG);
        $this->actingAs($this->host)->deleteJson("/api/plazas/{$other->id}/kicks/{$kickId}")->assertNotFound();

        // 방장 넘김 → 새 방장이 목록 관리, 이전 방장은 못 함
        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/transfer", ['user_id' => $next->id])->assertOk();
        $this->actingAs($this->host)->getJson("/api/plazas/{$this->plaza->id}/kicks")->assertForbidden();
        $this->actingAs($next)->getJson("/api/plazas/{$this->plaza->id}/kicks")->assertJsonCount(1, 'data');
        $this->actingAs($next)->deleteJson("/api/plazas/{$this->plaza->id}/kicks/{$kickId}")->assertOk();
        $this->actingAs($next)->deleteJson("/api/plazas/{$this->plaza->id}/kicks/{$kickId}")
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_REVOKED');
    }

    /** 방장 권한 남용 방지: 광장당 24시간 10명까지 */
    public function test_kick_daily_limit(): void
    {
        $limit = config('social.plaza.kick_daily_limit');
        for ($i = 0; $i < $limit; $i++) {
            $this->kick($this->host, $this->guest())->assertOk();
        }
        $this->kick($this->host, $this->guest())->assertStatus(429)->assertJsonPath('code', 'KICK_LIMIT');
        $this->assertSame($limit, PlazaKick::where('plaza_id', $this->plaza->id)->count());

        // 해제해도 한도는 그대로 (넣었다 뺐다 반복 방지)
        $first = PlazaKick::where('plaza_id', $this->plaza->id)->orderBy('id')->first();
        $this->actingAs($this->host)->deleteJson("/api/plazas/{$this->plaza->id}/kicks/{$first->id}")->assertOk();
        $this->kick($this->host, $this->guest())->assertStatus(429);
    }

    /* ---------------- 넘기기 ---------------- */

    public function test_host_can_transfer_to_eligible_member(): void
    {
        $low = $this->guest(100);
        $ok = $this->guest(500);
        $outside = User::factory()->complete()->create(); // 광장에 없음

        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/transfer", ['user_id' => $low->id])
            ->assertStatus(409)->assertJsonPath('code', 'NOT_ELIGIBLE');
        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/transfer", ['user_id' => $outside->id])
            ->assertStatus(409)->assertJsonPath('code', 'NOT_IN_PLAZA');
        $this->actingAs($ok)->postJson("/api/plazas/{$this->plaza->id}/transfer", ['user_id' => $low->id])
            ->assertForbidden()->assertJsonPath('code', 'NOT_HOST');

        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/transfer", ['user_id' => $ok->id])->assertOk();
        $this->assertSame($ok->id, (int) $this->plaza->fresh()->host_id);
        Event::assertDispatched(PlazaHostChanged::class, fn ($e) => $e->host['id'] === $ok->id);

        // 넘긴 뒤 이전 방장은 권한 없음
        $this->kick($this->host, $low)->assertForbidden();
    }

    /* ---------------- 닫기 ---------------- */

    public function test_host_can_close_plaza(): void
    {
        $guest = $this->guest();
        $this->actingAs($guest)->postJson("/api/plazas/{$this->plaza->id}/close")->assertForbidden();

        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/close")->assertOk();
        $plaza = $this->plaza->fresh();
        $this->assertSame(Plaza::STATUS_CLOSED, $plaza->status);
        $this->assertSame('host_closed', $plaza->closed_reason);
        $this->assertNotNull($plaza->closed_at);
        Event::assertDispatched(PlazaClosed::class, fn ($e) => $e->reason === 'host_closed');

        $this->actingAs($guest)->postJson("/api/plazas/{$this->plaza->id}/heartbeat")->assertStatus(410);
    }

    /** 관리자 닫기 (신고 화면의 '광장 닫기' 버튼이 부르는 메서드) — 두 번 눌러도 한 번만 */
    public function test_admin_close_is_idempotent(): void
    {
        $svc = app(PlazaService::class);
        $this->assertTrue($svc->close($this->plaza, 'admin'));
        $this->assertFalse($svc->close($this->plaza, 'admin'));
        $this->assertSame('admin', $this->plaza->fresh()->closed_reason);
        Event::assertDispatchedTimes(PlazaClosed::class, 1);
    }

    /* ---------------- 닫힌 광장 대화 정리 ---------------- */

    public function test_purge_deletes_old_closed_plaza_messages_except_reported(): void
    {
        $guest = $this->guest();
        $send = fn (Plaza $p, string $body) => $p->chatRoom()->firstOrFail()->messages()->create(['sender_id' => $guest->id, 'body' => $body]);

        $old = $this->plaza;
        $normal = $send($old, '평범한 말');
        $reported = $send($old, '신고된 말');
        Report::create([
            'reporter_id' => $this->host->id, 'target_id' => $guest->id,
            'target_type' => 'message', 'target_ref_id' => $reported->id, 'reason' => 'abuse',
        ]);
        app(PlazaService::class)->close($old, 'host_closed');

        // 비교군: 최근에 닫힌 광장, 열려 있는 광장
        $this->travel(config('social.plaza.closed_message_days') - 1)->days();
        $recent = app(PlazaService::class)->create($this->host, ['name' => '최근 닫힘', 'category' => 'talk'], self::LAT, self::LNG);
        $recentMsg = $send($recent, '최근 말');
        app(PlazaService::class)->close($recent, 'host_closed');
        $open = app(PlazaService::class)->create($this->host, ['name' => '열린 방', 'category' => 'talk'], self::LAT, self::LNG);
        $openMsg = $send($open, '열린 방 말');

        $this->travel(2)->days(); // old는 31일, recent는 2일 경과

        $this->artisan('plazas:purge')->assertSuccessful();

        $this->assertNull(ChatMessage::find($normal->id));
        $this->assertNotNull(ChatMessage::find($reported->id));   // 증거 보존
        $this->assertNotNull(ChatMessage::find($recentMsg->id));
        $this->assertNotNull(ChatMessage::find($openMsg->id));
    }

    public function test_purge_removes_old_revoked_kicks_only(): void
    {
        [$a, $b] = [$this->guest(), $this->guest()];
        $revokedId = $this->kick($this->host, $a)->assertOk()->json('kick_id');
        $activeId = $this->kick($this->host, $b)->assertOk()->json('kick_id');
        $this->actingAs($this->host)->deleteJson("/api/plazas/{$this->plaza->id}/kicks/{$revokedId}")->assertOk();

        $this->travel(8)->days(); // 해제한 지 7일 넘음
        $this->artisan('plazas:purge')->assertSuccessful();

        $this->assertNull(PlazaKick::find($revokedId));
        $this->assertNotNull(PlazaKick::find($activeId)); // 해제 안 된 강퇴는 계속 유지
    }
}
