<?php

namespace Tests\Feature;

use App\Events\PlazaClosed;
use App\Events\PlazaHostChanged;
use App\Models\Block;
use App\Models\Plaza;
use App\Models\PlazaKick;
use App\Models\PlazaMember;
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
            app(PlazaService::class)->enter($u, $far, $far->lat, $far->lng);
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

    public function test_search_requires_every_word(): void
    {
        // 방장 한 명당 광장 3개 제한이 있어 방마다 다른 방장
        $match = $this->makePlaza(User::factory()->complete()->create(), ['name' => '강남역 러닝크루', 'category' => 'sports']);
        $this->makePlaza(User::factory()->complete()->create(), ['name' => '강남 수다방']);
        $this->makePlaza(User::factory()->complete()->create(), ['name' => '역삼 러닝', 'category' => 'sports']);
        $me = User::factory()->complete()->create();
        $q = '?lat='.self::LAT.'&lng='.self::LNG;

        // 두 단어가 모두 들어간 방만 (사이에 글자가 껴도 됨, 여러 칸 띄어쓰기도 같음)
        $res = $this->actingAs($me)->getJson("/api/plazas{$q}&q=".urlencode('강남  러닝'))->assertOk();
        $this->assertSame([$match->id], array_column($res->json('data'), 'id'));

        // 설명에 있는 단어도 찾음
        $desc = $this->makePlaza(User::factory()->complete()->create(), ['name' => '저녁 모임', 'description' => '강남에서 러닝해요']);
        $res = $this->actingAs($me)->getJson("/api/plazas{$q}&q=".urlencode('강남 러닝'))->assertOk();
        $this->assertEqualsCanonicalizing([$match->id, $desc->id], array_column($res->json('data'), 'id'));

        // LIKE 와일드카드는 글자로 취급: '%'로 모든 방이 걸리면 안 됨
        $this->actingAs($me)->getJson("/api/plazas{$q}&q=".urlencode('%'))->assertJsonCount(0, 'data');
    }

    public function test_search_matches_part_of_category_label(): void
    {
        $sports = $this->makePlaza(User::factory()->complete()->create(), ['name' => '아침 모임', 'category' => 'sports']);
        $this->makePlaza(User::factory()->complete()->create(), ['name' => '저녁 모임', 'category' => 'talk']);
        $me = User::factory()->complete()->create();
        $q = '?lat='.self::LAT.'&lng='.self::LNG;

        // '운' → 운동 카테고리
        $res = $this->actingAs($me)->getJson("/api/plazas{$q}&q=".urlencode('운'))->assertOk();
        $this->assertSame([$sports->id], array_column($res->json('data'), 'id'));
        // 단어마다 따로 걸러짐: '운동 저녁' = 운동 카테고리이면서 '저녁'도 있어야 함 → 둘 다 갖춘 방 없음
        $this->actingAs($me)->getJson("/api/plazas{$q}&q=".urlencode('운동 저녁'))->assertJsonCount(0, 'data');
        // 해당 카테고리 방이 없으면 0개
        $this->actingAs($me)->getJson("/api/plazas{$q}&q=".urlencode('스터'))->assertJsonCount(0, 'data');
    }

    public function test_search_still_hides_blocked_kicked_and_closed(): void
    {
        $me = User::factory()->complete()->create();
        $blocker = User::factory()->complete()->create();
        $this->makePlaza($blocker, ['name' => '러닝 A']);
        Block::create(['blocker_id' => $blocker->id, 'blocked_id' => $me->id]);   // 방장이 나를 차단

        $kicked = $this->makePlaza(User::factory()->complete()->create(), ['name' => '러닝 B']);
        PlazaKick::create(['plaza_id' => $kicked->id, 'user_id' => $me->id, 'kicked_by' => $kicked->host_id, 'reason' => 'spam']);

        $closed = $this->makePlaza(User::factory()->complete()->create(), ['name' => '러닝 C']);
        $closed->update(['status' => Plaza::STATUS_CLOSED, 'closed_at' => now()]);

        $ok = $this->makePlaza(User::factory()->complete()->create(), ['name' => '러닝 D']);

        $res = $this->actingAs($me)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG.'&q='.urlencode('러닝'))->assertOk();
        $this->assertSame([$ok->id], array_column($res->json('data'), 'id'));
    }

    public function test_search_finds_plazas_anywhere_but_list_is_nearby(): void
    {
        $near = $this->makePlaza(User::factory()->complete()->create(), ['name' => '역삼 러닝', 'category' => 'sports']);
        $busan = $this->makePlaza(User::factory()->complete()->create(), ['name' => '해운대 러닝', 'category' => 'sports'], 35.1587, 129.1604);
        $me = User::factory()->complete()->create();
        $q = '?lat='.self::LAT.'&lng='.self::LNG;

        // 기본 목록(검색 없음)은 5km 안만
        $res = $this->actingAs($me)->getJson("/api/plazas{$q}")->assertOk();
        $this->assertSame([$near->id], array_column($res->json('data'), 'id'));

        // 검색은 전국, 가까운 순. 5km 밖은 can_enter=false + 대략 구간만
        $res = $this->actingAs($me)->getJson("/api/plazas{$q}&q=".urlencode('러닝'))->assertOk();
        $this->assertSame([$near->id, $busan->id], array_column($res->json('data'), 'id'));
        $this->assertTrue($res->json('data.0.can_enter'));
        $this->assertFalse($res->json('data.1.can_enter'));
        $this->assertSame('30km 이상', $res->json('data.1.distance_label'));

        // 카테고리만 골라도 '찾기' → 전국
        $this->actingAs($me)->getJson("/api/plazas{$q}&category=sports")->assertJsonCount(2, 'data');
    }

    public function test_search_without_location(): void
    {
        $this->makePlaza(User::factory()->complete()->create(), ['name' => '러닝크루', 'category' => 'sports']);
        $me = User::factory()->complete()->create();

        // 위치 권한 없음: 검색은 되지만 들어갈 수 있는지는 모름(null)
        $res = $this->actingAs($me)->getJson('/api/plazas?q='.urlencode('러닝'))->assertOk()->assertJsonCount(1, 'data');
        $this->assertNull($res->json('data.0.can_enter'));
        $this->assertNull($res->json('data.0.distance_label'));
        // 위치 없이 '내 주변' 목록은 빈 목록
        $this->actingAs($me)->getJson('/api/plazas')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_enter_requires_location_within_radius(): void
    {
        $host = User::factory()->complete()->create();
        $busan = $this->makePlaza($host, ['name' => '해운대'], 35.1587, 129.1604);
        $me = User::factory()->complete()->create();
        $here = ['lat' => self::LAT, 'lng' => self::LNG];

        // 멀면 거절, 위치 없으면 거절
        $this->actingAs($me)->postJson("/api/plazas/{$busan->id}/enter", $here)
            ->assertForbidden()->assertJsonPath('code', 'OUT_OF_RANGE');
        $this->actingAs($me)->postJson("/api/plazas/{$busan->id}/enter")
            ->assertForbidden()->assertJsonPath('code', 'LOCATION_REQUIRED');
        // 좌표 반쪽만은 검증 오류
        $this->actingAs($me)->postJson("/api/plazas/{$busan->id}/enter", ['lat' => 35.1587])->assertUnprocessable();

        // 근처에서는 들어감
        $atBusan = ['lat' => 35.1590, 'lng' => 129.1600];
        $this->actingAs($me)->postJson("/api/plazas/{$busan->id}/enter", $atBusan)->assertOk();
        // 이미 안에 있으면 좌표 없이 재입장(재연결) 가능 → 광장 안에서 움직여도 안 쫓겨남
        $this->actingAs($me)->postJson("/api/plazas/{$busan->id}/enter")->assertOk();

        // 방장은 거리와 상관없이 자기 광장에 들어감
        app(\App\Services\Plaza\PlazaService::class)->leave($host, $busan);
        $this->actingAs($host)->postJson("/api/plazas/{$busan->id}/enter", $here)->assertOk();
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

        $this->actingAs($a)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
        $this->actingAs($b)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
        $c = User::factory()->complete()->create();
        $this->actingAs($c)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertStatus(409)->assertJson(['code' => 'PLAZA_FULL']);
    }

    public function test_chat_requires_entry_and_hides_blocked_senders(): void
    {
        $plaza = $this->makePlaza(User::factory()->complete()->create());
        [$me, $other, $troll] = User::factory()->count(3)->complete()->create();

        $this->actingAs($me)->postJson("/api/plazas/{$plaza->id}/messages", ['body' => '안녕'])
            ->assertForbidden()->assertJson(['code' => 'NOT_IN_PLAZA']);

        foreach ([$me, $other, $troll] as $u) {
            $this->actingAs($u)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
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
            app(PlazaService::class)->enter($u, $busy, $busy->lat, $busy->lng);
        }
        $q = '?lat='.self::LAT.'&lng='.self::LNG;

        $this->assertSame($busy->id, $this->actingAs($host)->getJson("/api/plazas{$q}&sort=busy")->json('data.0.id'));
        $this->assertSame($new->id, $this->actingAs($host)->getJson("/api/plazas{$q}&sort=new")->json('data.0.id'));
        $this->assertSame($near->id, $this->actingAs($host)->getJson("/api/plazas{$q}")->json('data.0.id'));
    }

    /** 타일: 대화 내용은 밖에 안 보이고 '대화 중' 표시만 (10분) */
    public function test_list_shows_chatting_without_content(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG]);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/messages", ['body' => '한판 ㄱ?'])->assertCreated();

        $outsider = User::factory()->complete()->create();
        $res = $this->actingAs($outsider)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)
            ->assertJsonPath('data.0.chatting', true)
            ->assertJsonMissingPath('data.0.last_message');
        // assertDontSee는 JSON에선 헛돎(한글이 \uXXXX로 나가서 원문 검색에 안 걸림) → 풀어서 검사
        $this->assertStringNotContainsString('한판', json_encode($res->json(), JSON_UNESCAPED_UNICODE));

        $this->travel(11)->minutes(); // 10분 지나면 '대화 중' 표시도 끔
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk(); // 방장 부재로 숨겨지지 않게
        $this->actingAs($host)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)
            ->assertJsonPath('data.0.chatting', false);
    }

    public function test_low_trust_user_cannot_host(): void
    {
        $user = User::factory()->complete()->create();
        $user->trustScore()->update(['score' => 100]);

        $this->actingAs($user)->postJson('/api/plazas', [
            'name' => '테스트', 'category' => 'talk', 'lat' => self::LAT, 'lng' => self::LNG,
        ])->assertForbidden()->assertJson(['code' => 'LOW_TRUST']);
    }

    /* ---------------- 방장이 72시간 안 오면 위임·닫힘 ---------------- */

    /** 방장이 host_grace(72시간) 동안 안 들어오고, 넘길 사람(자격 있는 참여자)도 없으면 닫힘 */
    public function test_plaza_closes_when_host_leaves_and_does_not_return(): void
    {
        Event::fake([PlazaClosed::class]);
        $host = User::factory()->complete()->create();
        $guest = $this->userWithScore(100); // 방장 자격 미달 → 위임 대상 없음
        $plaza = $this->makePlaza($host);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
        $this->actingAs($guest)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();

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

        // 닫힌 광장의 참여는 정리 → 채팅 탭 목록에서 빠짐
        $this->assertSame(0, PlazaMember::where('plaza_id', $plaza->id)->count());
    }

    /** 72시간 안에 한 번이라도 들어오면 유지 */
    public function test_host_returning_within_grace_keeps_plaza_open(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();

        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/leave")->assertOk();
        $this->travel(config('social.plaza.host_grace') - 30)->seconds();
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
        $this->travel(60)->seconds(); // 처음 나간 시점 기준으론 72시간 초과지만, 중간에 들어왔으니 유지

        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        $this->assertSame(Plaza::STATUS_OPEN, $plaza->fresh()->status);
    }

    /** 방장이 heartbeat만 보내고 있어도(광장 화면에 머묾) 닫히지 않음 */
    public function test_host_heartbeat_keeps_plaza_open(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();

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
        $this->actingAs($guest)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])
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
     * 방장 부재 확정까지 시간 보내기. 손님들은 접속(presence)이 끊겨도 참여(plaza_members)는 남아 있어
     * 위임 후보가 된다 — 전에는 heartbeat로 접속을 유지해야 후보였음.
     */
    private function stayUntilHostGone(Plaza $plaza, array $guests): void
    {
        $this->travel(config('social.plaza.host_grace') + 1)->seconds();
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
            $this->actingAs($u)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
        }
        $this->stayUntilHostGone($plaza, [$low, $mid, $busy]);
        // busy가 운영 중인 광장 3개 (방장 부재로 안 세지도록 위임 직전에 만듦)
        for ($i = 0; $i < config('social.plaza.max_open_per_host'); $i++) {
            $this->makePlaza($busy, ['name' => "운영중{$i}"]);
        }

        $this->artisan('plazas:close-abandoned')->assertSuccessful();

        $plaza->refresh();
        $this->assertSame(Plaza::STATUS_OPEN, $plaza->status);
        $this->assertSame($mid->id, (int) $plaza->host_id);
        Event::assertDispatched(PlazaHostChanged::class, fn ($e) => $e->plazaId === $plaza->id && $e->host['id'] === $mid->id);
        Event::assertNotDispatched(PlazaClosed::class);

        // 새 방장이 들어오면 유지 (참여자라 좌표 없이 재입장, host_seen_at 갱신)
        $this->actingAs($mid)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
        $this->actingAs($mid)->getJson("/api/plazas/{$plaza->id}")->assertJsonPath('data.is_host', true);
        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        Event::assertDispatchedTimes(PlazaHostChanged::class, 1); // 중복 위임 없음
    }

    /** 자격 있는 참여자가 없으면 닫힘. 정리 명령 전이라도 손님 요청에서 바로 처리 */
    public function test_plaza_closes_when_no_eligible_member(): void
    {
        Event::fake([PlazaHostChanged::class, PlazaClosed::class]);
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $low = $this->userWithScore(100);
        $this->actingAs($low)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();

        $this->travel(config('social.plaza.host_grace') + 1)->seconds();

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
        $this->actingAs($member)->postJson("/api/plazas/{$plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
        $this->stayUntilHostGone($plaza, [$member]);

        $member->forceFill(['status' => 'suspended'])->save(); // 접속 중에 정지됨

        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        $this->assertSame(Plaza::STATUS_CLOSED, $plaza->fresh()->status);
        Event::assertNotDispatched(PlazaHostChanged::class);
    }

    /* ---------------- 참여 유지 · 나가기 · 채팅 탭 광장 ---------------- */

    /**
     * 채팅 탭 '광장'의 광장 id 목록.
     * 주의: array_column은 'plaza.id' 같은 점 표기를 못 읽어 늘 []을 돌려줬음 → '빈 목록' 검사가 전부 그냥 통과했었다 (2026-11-19 발견).
     * pluck은 점 표기로 안쪽 값을 읽는다.
     */
    private function joinedIds(User $u): array
    {
        return collect($this->actingAs($u)->getJson('/api/me/plazas')->assertOk()->json('data'))->pluck('plaza.id')->all();
    }

    /** 화면을 떠나도(leave) 참여 유지, 나가기(quit)를 눌러야 빠짐. 참여 중이면 어디서든 다시 들어옴 */
    public function test_membership_persists_until_quit(): void
    {
        $plaza = $this->makePlaza(User::factory()->complete()->create());
        $me = User::factory()->complete()->create();
        $url = "/api/plazas/{$plaza->id}";

        $this->actingAs($me)->postJson("{$url}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
        $this->actingAs($me)->postJson("{$url}/leave")->assertOk();
        $this->travel(2)->days();
        $this->assertSame([$plaza->id], $this->joinedIds($me));

        // 멀리(부산) 있어도 참여자는 다시 들어옴
        $this->actingAs($me)->postJson("{$url}/enter", ['lat' => 35.1587, 'lng' => 129.1604])->assertOk();

        // 나가기 → 목록에서 빠지고, 다시 들어오려면 거리 검사부터
        $this->actingAs($me)->postJson("{$url}/quit")->assertOk();
        $this->actingAs($me)->postJson("{$url}/quit")->assertOk(); // 두 번 눌러도 안전
        $this->assertSame([], $this->joinedIds($me));
        $this->actingAs($me)->postJson("{$url}/enter", ['lat' => 35.1587, 'lng' => 129.1604])
            ->assertForbidden()->assertJsonPath('code', 'OUT_OF_RANGE');
    }

    /** 정원은 참여자 수: 화면을 떠난 참여자도 자리를 차지, 나가기 하면 자리가 남 */
    public function test_capacity_counts_members(): void
    {
        $plaza = $this->makePlaza(User::factory()->complete()->create(), ['capacity' => 2]);
        [$a, $b, $c] = User::factory()->count(3)->complete()->create();
        $here = ['lat' => self::LAT, 'lng' => self::LNG];
        $url = "/api/plazas/{$plaza->id}";

        $this->actingAs($a)->postJson("{$url}/enter", $here)->assertOk();
        $this->actingAs($b)->postJson("{$url}/enter", $here)->assertOk();
        $this->actingAs($a)->postJson("{$url}/leave")->assertOk(); // 화면만 떠남
        $this->actingAs($c)->postJson("{$url}/enter", $here)->assertStatus(409)->assertJsonPath('code', 'PLAZA_FULL');

        $this->actingAs($a)->postJson("{$url}/quit")->assertOk();
        $this->actingAs($c)->postJson("{$url}/enter", $here)->assertOk();
    }

    public function test_join_limit(): void
    {
        config(['social.plaza.max_joined' => 2]);
        $me = User::factory()->complete()->create();
        $plazas = collect(range(1, 3))->map(fn ($i) => $this->makePlaza(User::factory()->complete()->create(), ['name' => "방{$i}"]));
        $here = ['lat' => self::LAT, 'lng' => self::LNG];

        $this->actingAs($me)->postJson("/api/plazas/{$plazas[0]->id}/enter", $here)->assertOk();
        $this->actingAs($me)->postJson("/api/plazas/{$plazas[1]->id}/enter", $here)->assertOk();
        $this->actingAs($me)->postJson("/api/plazas/{$plazas[2]->id}/enter", $here)
            ->assertStatus(409)->assertJsonPath('code', 'TOO_MANY_JOINED');

        // 닫힌 광장은 세지 않음
        app(PlazaService::class)->close($plazas[0], 'host_closed');
        $this->actingAs($me)->postJson("/api/plazas/{$plazas[2]->id}/enter", $here)->assertOk();
    }

    /** 방장이 나가기 → 기다리지 않고 바로 자격 있는 참여자에게 위임, 없으면 닫힘 */
    public function test_host_quit_hands_over_immediately(): void
    {
        Event::fake([PlazaHostChanged::class, PlazaClosed::class]);
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        $here = ['lat' => self::LAT, 'lng' => self::LNG];
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter", $here)->assertOk();
        $next = $this->userWithScore(600);
        $this->actingAs($next)->postJson("/api/plazas/{$plaza->id}/enter", $here)->assertOk();
        $this->actingAs($next)->postJson("/api/plazas/{$plaza->id}/leave")->assertOk(); // 접속 안 해도 참여자면 후보

        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/quit")->assertOk();
        $this->assertSame($next->id, (int) $plaza->fresh()->host_id);
        Event::assertDispatched(PlazaHostChanged::class, fn ($e) => $e->host['id'] === $next->id);
        $this->assertSame([], $this->joinedIds($host));

        // 새 방장도 나가면 남은 사람이 없어 닫힘
        $this->actingAs($next)->postJson("/api/plazas/{$plaza->id}/quit")->assertOk();
        $this->assertSame(Plaza::STATUS_CLOSED, $plaza->fresh()->status);
        Event::assertDispatched(PlazaClosed::class, fn ($e) => $e->reason === 'host_left');
    }

    /** 채팅 탭 '광장': 안 읽은 수(내 메시지·차단 상대 제외), 읽음 처리, 강퇴되면 목록에서 빠짐 */
    public function test_joined_list_unread_and_read(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        [$me, $friend, $troll] = User::factory()->count(3)->complete()->create();
        $here = ['lat' => self::LAT, 'lng' => self::LNG];
        $url = "/api/plazas/{$plaza->id}";

        $this->actingAs($host)->postJson("{$url}/enter", $here)->assertOk();
        $this->actingAs($host)->postJson("{$url}/messages", ['body' => '참여 전 대화'])->assertCreated();
        foreach ([$me, $friend, $troll] as $u) {
            $this->actingAs($u)->postJson("{$url}/enter", $here)->assertOk();
        }
        Block::create(['blocker_id' => $me->id, 'blocked_id' => $troll->id]);
        $this->actingAs($me)->postJson("{$url}/leave")->assertOk();

        $this->actingAs($friend)->postJson("{$url}/messages", ['body' => '하나'])->assertCreated();
        $lastId = $this->actingAs($friend)->postJson("{$url}/messages", ['body' => '둘'])->json('data.id');
        $this->actingAs($troll)->postJson("{$url}/messages", ['body' => '차단된 말'])->assertCreated();

        // 참여 전 대화·차단 상대 말은 안 셈
        $res = $this->actingAs($me)->getJson('/api/me/plazas')->assertOk();
        $this->assertSame(2, $res->json('data.0.unread'));
        $this->assertSame(2, $res->json('unread_total'));
        $this->assertSame('둘', $res->json('data.0.last_message.body'));
        $this->assertSame(4, $res->json('data.0.member_count'));

        $this->actingAs($me)->postJson("{$url}/read", ['last_id' => $lastId])->assertOk();
        $this->actingAs($me)->postJson("{$url}/read", ['last_id' => 1])->assertOk(); // 뒤로는 안 감
        $this->actingAs($me)->postJson("{$url}/read", ['last_id' => 999999])->assertOk(); // 마지막 메시지보다 앞으로도 안 감
        $this->assertSame(0, $this->actingAs($me)->getJson('/api/me/plazas')->json('data.0.unread'));
        $this->actingAs($host)->postJson("{$url}/messages", ['body' => '셋'])->assertCreated();
        $this->assertSame(1, $this->actingAs($me)->getJson('/api/me/plazas')->json('data.0.unread'));

        // 강퇴되면 참여에서 빠짐 (먼저 '있음'을 확인 — 빈 목록 검사가 헛돌지 않게)
        $this->assertSame([$plaza->id], $this->joinedIds($me));
        $this->actingAs($host)->postJson("{$url}/kick", ['user_id' => $me->id, 'reason' => 'spam'])->assertOk();
        $this->assertSame([], $this->joinedIds($me));
    }

    /** 14일(member_idle_days) 동안 안 들어온 참여자는 새벽 정리에서 빠짐. 방장은 제외 */
    public function test_purge_removes_idle_members_except_host(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        [$idle, $active] = User::factory()->count(2)->complete()->create();
        $here = ['lat' => self::LAT, 'lng' => self::LNG];
        foreach ([$host, $idle, $active] as $u) {
            $this->actingAs($u)->postJson("/api/plazas/{$plaza->id}/enter", $here)->assertOk();
        }

        $days = (int) config('social.plaza.member_idle_days');
        $this->travel($days - 1)->days();
        // 방장도 들어와야 72시간 부재 위임이 먼저 일어나지 않음
        $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk();
        $this->actingAs($active)->postJson("/api/plazas/{$plaza->id}/enter")->assertOk(); // 참여자라 좌표 없이 재입장 → 기준 갱신
        $this->travel(2)->days(); // idle은 기준 초과, active는 2일 전 방문
        // 방장 행도 오래된 것으로 만들어 '방장 제외'를 확인
        PlazaMember::where('plaza_id', $plaza->id)->where('user_id', $host->id)->update(['last_seen_at' => now()->subDays($days + 5)]);

        $this->artisan('plazas:purge')->assertSuccessful();

        $left = PlazaMember::where('plaza_id', $plaza->id)->pluck('user_id')->sort()->values()->all();
        $this->assertSame(collect([$host->id, $active->id])->sort()->values()->all(), $left);
        $this->assertSame([], $this->joinedIds($idle));
        $this->assertSame([$plaza->id], $this->joinedIds($active)); // 남은 사람은 목록에 있음 (헬퍼가 빈 값만 내는 게 아님)
    }

    /** 대화 읽기도 입장과 같은 기준: 참여자·방장만. 강퇴·나가기·참여 안 함·닫힘이면 못 읽음 */
    public function test_only_members_can_read_plaza_chat(): void
    {
        $host = User::factory()->complete()->create();
        $plaza = $this->makePlaza($host);
        [$member, $outsider, $kicked, $quitter] = User::factory()->count(4)->complete()->create();
        $here = ['lat' => self::LAT, 'lng' => self::LNG];
        $url = "/api/plazas/{$plaza->id}";
        foreach ([$host, $member, $kicked, $quitter] as $u) {
            $this->actingAs($u)->postJson("{$url}/enter", $here)->assertOk();
        }
        $this->actingAs($member)->postJson("{$url}/messages", ['body' => '우리끼리 얘기'])->assertCreated();

        // 참여자는 화면을 떠나 있어도 읽음 (채팅 탭에서 이어 보기), 방장도 읽음
        $this->actingAs($member)->postJson("{$url}/leave")->assertOk();
        $this->actingAs($member)->getJson("{$url}/messages")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($host)->getJson("{$url}/messages")->assertOk();

        // 참여 안 한 사람(번호만 아는 사람)
        $this->actingAs($outsider)->getJson("{$url}/messages")->assertForbidden()->assertJsonPath('code', 'NOT_IN_PLAZA');
        // 강퇴된 사람
        $this->actingAs($host)->postJson("{$url}/kick", ['user_id' => $kicked->id, 'reason' => 'spam'])->assertOk();
        $this->actingAs($kicked)->getJson("{$url}/messages")->assertForbidden()->assertJsonPath('code', 'KICKED');
        // 나간 사람
        $this->actingAs($quitter)->postJson("{$url}/quit")->assertOk();
        $this->actingAs($quitter)->getJson("{$url}/messages")->assertForbidden();
        // 닫힌 광장은 참여가 정리돼 손님은 못 읽음
        app(PlazaService::class)->close($plaza->fresh(), 'host_closed');
        $this->actingAs($member)->getJson("{$url}/messages")->assertForbidden();
    }
}
