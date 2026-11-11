<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Region\KakaoRegionResolver;
use App\Services\Region\RegionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 동네 상시 광장: 동네 찾기, 구역·채널 배정, 구역별 대화, 메인 카드 */
class TownTest extends TestCase
{
    use RefreshDatabase;

    // 역삼역 근처
    private const LAT = 37.5006;
    private const LNG = 127.0364;

    private function town(User $u): array
    {
        return $this->actingAs($u)->getJson('/api/town?lat='.self::LAT.'&lng='.self::LNG)->assertOk()->json('data');
    }

    private function enter(User $u, int $townId, array $body = [])
    {
        return $this->actingAs($u)->postJson("/api/plazas/{$townId}/town/enter", $body);
    }

    /* ---------------- 동네 광장 찾기 ---------------- */

    public function test_town_plaza_is_created_once_per_region(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();

        $first = $this->town($a);
        $second = $this->town($b);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('역삼동 광장', $first['name']);
        $this->assertSame('역삼동', $first['region_name']);
        $this->assertSame(1, Plaza::where('type', Plaza::TYPE_TOWN)->count());
        $this->assertNull(Plaza::find($first['id'])->host_id); // 방장 없음

        // 주민 광장 목록에는 안 섞임
        $this->actingAs($a)->getJson('/api/plazas?lat='.self::LAT.'&lng='.self::LNG)->assertJsonCount(0, 'data');
    }

    /** 운영 드라이버: 행정동(H)이 아니라 법정동(B) 이름, 같은 칸은 캐시로 API 1번만 */
    public function test_kakao_resolver_uses_legal_dong_and_caches(): void
    {
        config(['services.kakao.rest_key' => 'test-key']);
        Http::fake(['dapi.kakao.com/*' => Http::response(['documents' => [
            ['region_type' => 'B', 'code' => '1168010100', 'region_2depth_name' => '강남구', 'region_3depth_name' => '역삼동'],
            ['region_type' => 'H', 'code' => '1168064000', 'region_2depth_name' => '강남구', 'region_3depth_name' => '역삼1동'],
        ]])]);
        $resolver = new KakaoRegionResolver;

        $region = $resolver->resolve(self::LAT, self::LNG);
        $resolver->resolve(self::LAT, self::LNG); // 같은 칸 → 캐시

        $this->assertSame('1168010100', $region->code);
        $this->assertSame('역삼동', $region->name);
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'KakaoAK test-key')
            && $req['x'] == self::LNG && $req['y'] == self::LAT); // x=경도, y=위도
    }

    public function test_kakao_failure_is_not_cached(): void
    {
        $this->app->instance(RegionResolver::class, new KakaoRegionResolver);
        Http::fake(['dapi.kakao.com/*' => Http::sequence()->push([], 500)->push(['documents' => [
            ['region_type' => 'B', 'code' => '1168010100', 'region_2depth_name' => '강남구', 'region_3depth_name' => '역삼동'],
        ]])]);
        $u = User::factory()->complete()->create();

        $this->actingAs($u)->getJson('/api/town?lat='.self::LAT.'&lng='.self::LNG)
            ->assertStatus(503)->assertJsonPath('code', 'REGION_UNAVAILABLE');
        $this->actingAs($u)->getJson('/api/town?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()->assertJsonPath('data.name', '역삼동 광장');
    }

    /* ---------------- 구역·채널 배정 ---------------- */

    public function test_enter_goes_to_busiest_zone_and_overflows_to_next_channel(): void
    {
        config(['social.town.channel_capacity' => 2]);
        [$a, $b, $c, $d] = User::factory()->count(4)->complete()->create();
        $id = $this->town($a)['id'];

        // 직접 고른 구역의 1채널
        $this->enter($a, $id, ['zone' => 'bench'])->assertOk()->assertJson(['zone' => 'bench', 'channel' => 1]);
        // 구역을 안 고르면 가장 북적이는 구역(벤치)으로
        $this->enter($b, $id)->assertOk()->assertJson(['zone' => 'bench', 'channel' => 1]);
        // 1채널(2명)이 차면 2채널
        $this->enter($c, $id)->assertOk()->assertJson(['zone' => 'bench', 'channel' => 2, 'presence_channel' => "town.{$id}.bench.2"]);

        // 가득 찬 채널을 직접 고르면 거절
        $this->enter($d, $id, ['zone' => 'bench', 'channel' => 1])->assertStatus(409)->assertJsonPath('code', 'CHANNEL_FULL');

        $state = $this->actingAs($a)->getJson("/api/plazas/{$id}/town")->assertOk()->json('data');
        $this->assertSame(3, $state['live_count']);
        $bench = collect($state['zones'])->firstWhere('key', 'bench');
        $this->assertSame([['no' => 1, 'count' => 2, 'full' => true], ['no' => 2, 'count' => 1, 'full' => false]], $bench['channels']);
    }

    /** 구역을 옮기면 이전 자리에서 빠짐 (한 사람은 한 곳에만) */
    public function test_moving_zone_leaves_previous_place(): void
    {
        $u = User::factory()->complete()->create();
        $id = $this->town($u)['id'];

        $this->enter($u, $id, ['zone' => 'fountain'])->assertOk();
        $this->enter($u, $id, ['zone' => 'field'])->assertOk()->assertJson(['zone' => 'field', 'channel' => 1]);

        $zones = collect($this->actingAs($u)->getJson("/api/plazas/{$id}/town")->json('data.zones'))->pluck('count', 'key');
        $this->assertSame(0, $zones['fountain']);
        $this->assertSame(1, $zones['field']);

        // 이전 자리 heartbeat는 NOT_IN_PLAZA
        $this->actingAs($u)->postJson("/api/plazas/{$id}/town/heartbeat", ['zone' => 'fountain', 'channel' => 1])->assertStatus(409);
        $this->actingAs($u)->postJson("/api/plazas/{$id}/town/heartbeat", ['zone' => 'field', 'channel' => 1])->assertOk();
    }

    /* ---------------- 구역 채팅 ---------------- */

    public function test_chat_is_scoped_to_zone_and_channel(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->complete()->create();
        $id = $this->town($a)['id'];
        $this->enter($a, $id, ['zone' => 'fountain'])->assertOk();
        $this->enter($b, $id, ['zone' => 'fountain'])->assertOk();
        $this->enter($c, $id, ['zone' => 'bench'])->assertOk();

        $this->actingAs($a)->postJson("/api/plazas/{$id}/town/messages", ['zone' => 'fountain', 'channel' => 1, 'body' => '붕어빵 트럭 왔어요'])
            ->assertCreated()->assertJsonPath('data.sender.id', $a->id);
        // 들어가지 않은 구역에는 말할 수 없음
        $this->actingAs($c)->postJson("/api/plazas/{$id}/town/messages", ['zone' => 'fountain', 'channel' => 1, 'body' => '끼어들기'])
            ->assertForbidden()->assertJsonPath('code', 'NOT_IN_PLAZA');

        $this->actingAs($b)->getJson("/api/plazas/{$id}/town/messages?zone=fountain&channel=1")->assertJsonCount(1, 'data');
        $this->actingAs($c)->getJson("/api/plazas/{$id}/town/messages?zone=bench&channel=1")->assertJsonCount(0, 'data');
    }

    /* ---------------- 메인 카드 ---------------- */

    public function test_summary_shows_count_bubbles_and_friends_here(): void
    {
        [$me, $friend, $stranger] = User::factory()->count(3)->complete()->create();
        Friendship::insert([
            ['user_id' => $me->id, 'friend_id' => $friend->id, 'created_at' => now()],
            ['user_id' => $friend->id, 'friend_id' => $me->id, 'created_at' => now()],
        ]);
        $id = $this->town($me)['id'];
        $this->enter($friend, $id, ['zone' => 'playground'])->assertOk();
        $this->enter($stranger, $id, ['zone' => 'bench'])->assertOk();
        $this->actingAs($stranger)->postJson("/api/plazas/{$id}/town/messages", ['zone' => 'bench', 'channel' => 1, 'body' => '점심 뭐 먹지?'])->assertCreated();

        $card = $this->town($me);
        $this->assertSame(2, $card['live_count']);
        $this->assertCount(2, $card['preview_avatars']);
        $this->assertSame(['점심 뭐 먹지?'], $card['bubbles']);
        $this->assertSame(1, $card['friends']['count']);

        // 10분 지나면 말풍선 숨김
        $this->travel(11)->minutes();
        $this->assertSame([], $this->town($me)['bubbles']);
    }

    /* ---------------- 주민 광장 규칙과 분리 ---------------- */

    public function test_town_plaza_is_not_handled_like_user_plaza(): void
    {
        $u = User::factory()->complete()->create();
        $id = $this->town($u)['id'];

        // 주민 광장 API로는 못 들어감
        $this->actingAs($u)->postJson("/api/plazas/{$id}/enter")->assertStatus(409)->assertJsonPath('code', 'TOWN_PLAZA');

        // 방장이 없어도 정리 명령이 닫지 않음
        $this->travel(1)->hours();
        $this->artisan('plazas:close-abandoned')->assertSuccessful();
        $this->assertSame(Plaza::STATUS_OPEN, Plaza::find($id)->status);

        // 일반 광장을 상시 광장 API로 부르면 404
        $userPlaza = app(\App\Services\Plaza\PlazaService::class)->create($u, ['name' => '수다방', 'category' => 'talk'], self::LAT, self::LNG);
        $this->enter($u, $userPlaza->id)->assertNotFound()->assertJsonPath('code', 'NOT_TOWN');
    }
}
