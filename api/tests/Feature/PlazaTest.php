<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
