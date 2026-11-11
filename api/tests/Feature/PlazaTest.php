<?php

namespace Tests\Feature;

use App\Events\PlazaClosed;
use App\Events\PlazaHostChanged;
use App\Models\Block;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PlazaTest extends TestCase
{
    use RefreshDatabase;

    // 역삼역 근처
    private const LAT = 37.5006;
    private const LNG = 127.0364;

    private function makePlaza(User $host, array $attrs = [], float $lat = self::LAT, float $lng = self::LNG): Plaza
    {
        return app(PlazaService::class)->create($host, $attrs + ['name' => '역삼 수다방', 'category' => 'talk'], $lat, $lng);
    }

    public function test_create_plaza_stores_cell_center_not_real_location(): void
    {
        $host = User::factory()->complete()->create();

        $res = $this->actingAs($host)->postJson('/api/plazas', [
            'name' => '역삼 퇴근길 수다방', 'category' => 'talk', 'lat' => self::LAT, 'lng' => self::LNG,
        ])->assertCreated()
            ->assertJsonPath('data.live_count', 1)            // 만든 사람은 자동 입장
            ->assertJsonPath('data.category_label', '수다')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonMissingPath('data.lat');              // 좌표 미노출

        $plaza = Plaza::find($res->json('data.id'));
        $this->assertSame(7, strlen($plaza->region_cell));
        $this->assertNotEquals(self::LAT, $plaza->lat);        // 격자 중심값으로 저장
        $this->assertEqualsWithDelta(self::LAT, $plaza->lat, 0.002);
        $this->assertNotNull($plaza->chatRoom);
    }

    public function test_list_sorted_by_distance_with_range_labels_and_hot(): void
    {
        $host = User::factory()->complete()->create();
        $far = $this->makePlaza($host, ['name' => '선릉'], 37.5045, 127.0490);   // 약 1.2km
        $near = $this->makePlaza($host, ['name' => '역삼'], 37.5010, 127.0370);  // 약 70m
        $this->makePlaza(User::factory()->complete()->create(), ['name' => '부산'], 35.1, 129.0); // 반경 밖

        // far 광장에 2명 접속 → hot은 far
        foreach (User::factory()->count(2)->complete()->create() as $u) {
            app(PlazaService::class)->enter($u, $far);
        }

        $res = $this->actingAs($host)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)->assertOk();

        $this->assertSame([$near->id, $far->id], array_column($res->json('data'), 'id'));
        $this->assertSame('500m 이내', $res->json('data.0.distance_label'));
        $this->assertSame('3km 이내', $res->json('data.1.distance_label'));
        $this->assertSame($far->id, $res->json('hot.id'));
        $this->assertSame(2, $res->json('hot.live_count'));
        $this->assertCount(2, $res->json('hot.preview_avatars'));
    }

    public function test_filter_by_category_and_search(): void
    {
        $host = User::factory()->complete()->create();
        $this->makePlaza($host, ['name' => '롤 듀오 구해요', 'category' => 'game']);
        $this->makePlaza($host, ['name' => '러닝크루', 'category' => 'sports']);
        $q = '?lat='.self::LAT.'&lng='.self::LNG;

        $this->actingAs($host)->getJson("/api/plazas{$q}&category=game")->assertJsonCount(1, 'data');
        $this->actingAs($host)->getJson("/api/plazas{$q}&q=러닝")->assertJsonCount(1, 'data');
        $this->actingAs($host)->getJson("/api/plazas{$q}&q=운동")->assertJsonCount(1, 'data'); // 카테고리 라벨 검색
    }

    public function test_blocked_hosts_plaza_is_hidden_both_ways(): void
    {
        $host = User::factory()->complete()->create();
        $viewer = User::factory()->complete()->create();
        $this->makePlaza($host);
        Block::create(['blocker_id' => $host->id, 'blocked_id' => $viewer->id]); // 방장이 나를 차단

        $this->actingAs($viewer)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)->assertJsonCount(0, 'data');
    }

    public function test_capacity_limit(): void
    {
        $plaza = $this->makePlaza(User::factory()->complete()->create(), ['capacity' => 2]);
        [$a, $b] = User::factory()->count(2)->complete()->create();

        $this->actingAs($a)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
        $this->actingAs($b)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
        $c = User::factory()->complete()->create();
        $this->actingAs($c)->postJson("/api/plazas/{$plaza->id}/enter")->assertStatus(409)->assertJson(['code' => 'PLAZA_FULL']);
    }

    public function test_chat_requires_entry_and_hides_blocked_senders(): void
    {
        $plaza = $this->makePlaza(User::factory()->complete()->create());
        [$me, $other, $troll] = User::factory()->count(3)->complete()->create();

        $this->actingAs($me)->postJson("/api/plazas/{$plaza->id}/messages", ['body' => '안녕'])
            ->assertForbidden()->assertJson(['code' => 'NOT_IN_PLAZA']);

        foreach ([$me, $other, $troll] as $u) {
            $this->actingAs($u)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
            $this->actingAs($u)->postJson("/api/plazas/{$plaza->id}/messages", ['body' => "hi {$u->nickname}"])
                ->assertCreated()
                ->assertJsonPath('data.sender.id', $u->id)
                ->assertJsonPath('data.type', 'text');   // DB 기본값이 생성 직후 응답에도 반영되는지
        }

        $this->actingAs($me)->postJson('/api/blocks', ['user_id' => $troll->id])->assertOk();

        $res = $this->actingAs($me)->getJson("/api/plazas/{$plaza->id}/messages")->assertOk();
        $senders = array_column(array_column($res->json('data'), 'sender'), 'id');
        $this->assertNotContains($troll->id, $senders);
        $this->assertSame([$other->id, $me->id], $senders); // 최신순
    }

    public function test_sort_busy_and_new(): void
    {
        $host = User::factory()->complete()->create();
        $near = $this->makePlaza($host, ['name' => '가까운'], 37.5010, 127.0370);
        $this->travel(1)->minutes();
        $busy = $this->makePlaza($host, ['name' => '북적'], 37.5045, 127.0490);
        $this->travel(1)->minutes();
        $new = $this->makePlaza($host, ['name' => '새것'], 37.5030, 127.0420);

        foreach (User::factory()->count(3)->complete()->create() as $u) {
            app(PlazaService::class)->enter($u, $busy);
        }
        $q = '?lat='.self::LAT.'&lng='.self::LNG;

        $this->assertSame($busy->id, $this->actingAs($host)->getJson("/api/plazas{$q}&sort=busy")->json('data.0.id'));
        $this->assertSame($new->id, $this->actingAs($host)->getJson("/api/plazas{$q}&sort=new")->json('data.0.id'));
        $this->assertSame($near->id, $this->actingAs($host)->getJson("/api/plazas{$q}")->json('data.0.id'));
    }

    /** 타일 말풍선: 방금 한 말이 목록에 보임 */
    public function test_list_shows_last_message_bubble(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter");
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/messages", ['body' => '한판 ㄱ?'])->assertCreated();

        $this->actingAs($host)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)
            ->assertJsonPath('data.0.last_message', '한판 ㄱ?');

        $this->travel(11)->minutes(); // 10분 지나면 말풍선 숨김
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk(); // 방장 부재로 숨겨지지 않게
        $this->actingAs($host)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)
            ->assertJsonPath('data.0.last_message', null);
    }

    public function test_low_trust_user_cannot_host(): void
    {
        $user = User::factory()->complete()->create();
        $user->trustScore()->update(['score' => 100]);

        $this->actingAs($user)->postJson('/api/plazas', [
            'name' => '테스트', 'category' => 'talk', 'lat' => self::LAT, 'lng' => self::LNG,
        ])->assertForbidden()->assertJson(['code' => 'LOW_TRUST']);
    }

    /* ---------------- 방장이 나가면 광장 닫힘 ---------------- */

    public function test_plaza_closes_when_host_leaves_and_does_not_return(): void
    {
        Event::fake([PlazaClosed::class]);
        $host = User::factory()->complete()->create();
        $guest = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
        $this->actingAs($guest)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();

        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/leave")->assertOk();
        $this->travel(config('social.plaza.host_grace') + 1)->seconds();

        // 목록에서는 정리 명령 전에도 바로 숨김
        $this->actingAs($guest)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)->assertJsonCount(0, 'data');

        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        $this->assertSame(Plaza::STATUS_CLOSED, $plaza->fresh()->status);
        Event::assertDispatchedTimes(PlazaClosed::class, 1);

        // 남아 있던 사람: heartbeat에서 410 → 앱이 목록으로
        $this->actingAs($guest)->postJson("/api/plazas/{$plaza->id}/heartbeat")
            ->assertStatus(410)->assertJson(['code' => 'PLAZA_CLOSED']);

        // 명령을 다시 돌려도 중복으로 닫거나 알리지 않음
        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        Event::assertDispatchedTimes(PlazaClosed::class, 1);
    }

    /** 프로필 보기·새로고침처럼 잠깐 나갔다 오면 유지 */
    public function test_host_returning_within_grace_keeps_plaza_open(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();

        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/leave")->assertOk();
        $this->travel(config('social.plaza.host_grace') - 30)->seconds();
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
        $this->travel(60)->seconds(); // 나간 시점 기준으론 유예시간 초과지만, 돌아왔으니 유지

        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        $this->assertSame(Plaza::STATUS_OPEN, $plaza->fresh()->status);
    }

    /** 방장이 heartbeat만 보내고 있어도(광장 화면에 머묾) 닫히지 않음 */
    public function test_host_heartbeat_keeps_plaza_open(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();

        for ($i = 0; $i < 10; $i++) { // 5분 동안 30초마다
            $this->travel(30)->seconds();
            $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/heartbeat")->assertOk();
        }

        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        $this->assertSame(Plaza::STATUS_OPEN, $plaza->fresh()->status);
    }

    /** 정리 명령 전이라도 새로 들어오려는 사람은 막고 그 자리에서 닫음 */
    public function test_guest_cannot_enter_after_host_gone(): void
    {
        Event::fake([PlazaClosed::class]);
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $this->travel(config('social.plaza.host_grace') + 1)->seconds();

        $guest = User::factory()->complete()->create();
        $this->actingAs($guest)->postJson("/api/plazas/{$plaza->id}/enter")
            ->assertStatus(410)->assertJson(['code' => 'PLAZA_CLOSED']);
        $this->assertSame(Plaza::STATUS_CLOSED, $plaza->fresh()->status);
        Event::assertDispatched(PlazaClosed::class);
    }

    /** 떠난 광장은 '동시에 3개' 제한에 세지 않음 */
    public function test_abandoned_plazas_do_not_count_toward_host_limit(): void
    {
        $host = User::factory()->complete()->create();
        for ($i = 0; $i < config('social.plaza.max_open_per_host'); $i++) {
            $this->makePlaza($host, ['name' => "방{$i}"]);
        }
        $this->travel(config('social.plaza.host_grace') + 1)->seconds();

        $this->makePlaza($host, ['name' => '새 방']);
        $this->assertSame(1, Plaza::alive()->where('host_id', $host->id)->count());
    }

    /* ---------------- 방장 위임 ---------------- */

    /**
     * 방장이 나간 채 유예시간이 지나는 동안 손님들은 30초마다 heartbeat로 광장에 머문다.
     * (presence TTL 60초라 heartbeat 없이 시간만 넘기면 접속자도 사라짐)
     */
    private function stayUntilHostGone(Plaza $plaza, array $guests): void
    {
        for ($i = 0; $i < 5; $i++) { // 150초: 아직 유예시간(180초) 안
            $this->travel(30)->seconds();
            foreach ($guests as $g) {
                $this->actingAs($g)->postJson("/api/plazas/{$plaza->id}/heartbeat")->assertOk();
            }
        }
        $this->travel(40)->seconds(); // 190초: 방장 부재 확정, 손님들은 아직 접속 중(마지막 heartbeat 40초 전)
    }

    private function userWithScore(int $score): User
    {
        $u = User::factory()->complete()->create();
        $u->trustScore()->update(['score' => $score]);

        return $u->fresh();
    }

    public function test_host_is_handed_to_highest_score_eligible_member(): void
    {
        Event::fake([PlazaHostChanged::class, PlazaClosed::class]);
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);

        $low = $this->userWithScore(100);   // 기준(300) 미달 → 제외
        $mid = $this->userWithScore(500);   // ← 위임 대상
        $busy = $this->userWithScore(900);  // 점수는 제일 높지만 이미 광장 3개 운영 → 제외
        foreach ([$low, $mid, $busy] as $u) {
            $this->actingAs($u)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
        }
        $this->stayUntilHostGone($plaza, [$low, $mid, $busy]);
        // busy가 운영 중인 광장 3개 (방장 부재로 안 세지도록 위임 직전에 만듦)
        for ($i = 0; $i < config('social.plaza.max_open_per_host'); $i++) {
            $this->makePlaza($busy, ['name' => "운영중{$i}"]);
        }

        // 방장은 없지만 사람이 있어 곧 위임될 방 → 목록에 그대로 보임
        $listed = array_column($this->actingAs($low)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)->json('data'), 'id');
        $this->assertContains($plaza->id, $listed);

        $this->artisan('plazas:close-abandoned')->assertSuccessful();

        $plaza->refresh();
        $this->assertSame(Plaza::STATUS_OPEN, $plaza->status);
        $this->assertSame($mid->id, (int) $plaza->host_id);
        Event::assertDispatched(PlazaHostChanged::class, fn ($e) => $e->plazaId === $plaza->id && $e->host['id'] === $mid->id);
        Event::assertNotDispatched(PlazaClosed::class);

        // 새 방장이 광장에 있는 동안은 유지 (heartbeat가 host_seen_at 갱신)
        $this->actingAs($mid)->postJson("/api/plazas/{$plaza->id}/heartbeat")->assertOk();
        $this->actingAs($mid)->getJson("/api/plazas/{$plaza->id}")->assertJsonPath('data.is_host', true);
        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        Event::assertDispatchedTimes(PlazaHostChanged::class, 1); // 중복 위임 없음
    }

    /** 자격 있는 사람이 없으면 닫힘. 정리 명령 전이라도 손님 heartbeat에서 바로 처리 */
    public function test_plaza_closes_when_no_eligible_member(): void
    {
        Event::fake([PlazaHostChanged::class, PlazaClosed::class]);
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $low = $this->userWithScore(100);
        $this->actingAs($low)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->travel(30)->seconds();
            $this->actingAs($low)->postJson("/api/plazas/{$plaza->id}/heartbeat")->assertOk();
        }
        $this->travel(40)->seconds();

        $this->actingAs($low)->postJson("/api/plazas/{$plaza->id}/heartbeat")
            ->assertStatus(410)->assertJson(['code' => 'PLAZA_CLOSED']);
        $this->assertSame(Plaza::STATUS_CLOSED, $plaza->fresh()->status);
        Event::assertNotDispatched(PlazaHostChanged::class);
        Event::assertDispatchedTimes(PlazaClosed::class, 1);
    }

    /** 정지된 계정은 점수가 높아도 방장이 될 수 없음 */
    public function test_inactive_member_is_not_eligible(): void
    {
        Event::fake([PlazaHostChanged::class, PlazaClosed::class]);
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $member = $this->userWithScore(900);
        $this->actingAs($member)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
        $this->stayUntilHostGone($plaza, [$member]);

        $member->forceFill(['status' => 'suspended'])->save(); // 접속 중에 정지됨

        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        $this->assertSame(Plaza::STATUS_CLOSED, $plaza->fresh()->status);
        Event::assertNotDispatched(PlazaHostChanged::class);
    }
}
