<?php

namespace Tests\Feature;

use App\Models\Plaza;
use App\Models\User;
use App\Services\Place\KakaoPlaceSearch;
use App\Services\Plaza\PlazaService;
use App\Services\Town\TownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 번개 장소: 카카오 검색으로 고르기 (테스트는 PLACE_DRIVER=fake, 카카오 드라이버는 Http::fake로)
 */
class MeetupPlaceTest extends TestCase
{
    use RefreshDatabase;

    private Plaza $plaza;
    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = User::factory()->complete()->create();
        $this->plaza = app(PlazaService::class)->create($this->host, ['name' => '역삼 수다방', 'category' => 'talk'], 37.5006, 127.0364);
    }

    private function place(float $lat, float $lng): array
    {
        return ['name' => '스타벅스 역삼역점', 'address' => '서울 강남구 테헤란로 101', 'lat' => $lat, 'lng' => $lng, 'kakao_id' => '12345'];
    }

    private function create(Plaza $plaza, array $over): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->host)->postJson("/api/plazas/{$plaza->id}/meetups", $over + [
            'title' => '커피 한잔해요', 'area_label' => '역삼동 근처',
            'starts_at' => now()->addHours(3)->toIso8601String(), 'capacity' => 4,
        ]);
    }

    public function test_search_returns_places_near_plaza(): void
    {
        $this->actingAs($this->host)->getJson("/api/places/search?q=카페&plaza_id={$this->plaza->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['kakao_id', 'name', 'category', 'address', 'lat', 'lng', 'distance_label']]]);

        $this->actingAs($this->host)->getJson('/api/places/search?q=카페')->assertUnprocessable(); // plaza_id 필수
    }

    public function test_search_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($this->host)->getJson("/api/places/search?q=카페{$i}&plaza_id={$this->plaza->id}")->assertOk();
        }
        $this->actingAs($this->host)->getJson("/api/places/search?q=카페&plaza_id={$this->plaza->id}")->assertStatus(429);
    }

    /** 장소 고르기 시작 정보: 주민 광장만 범위 원(5km), 자유광장은 처음 위치 없음 */
    public function test_scope_by_plaza_kind(): void
    {
        $this->actingAs($this->host)->getJson("/api/places/scope?plaza_id={$this->plaza->id}")
            ->assertOk()->assertJsonPath('data.kind', 'user')->assertJsonPath('data.radius_m', 5000);

        $free = app(TownService::class)->free();
        $this->actingAs($this->host)->getJson("/api/places/scope?plaza_id={$free->id}")
            ->assertJsonPath('data.kind', 'free')->assertJsonPath('data.center', null)->assertJsonPath('data.radius_m', null);
    }

    /** 지도 핀 위치 → 주소·건물 이름 + 범위 안 여부 (화면은 범위 밖이면 '정하기'를 막음) */
    public function test_pin_address_and_reachable(): void
    {
        $this->actingAs($this->host)->getJson("/api/places/address?plaza_id={$this->plaza->id}&lat=37.5016&lng=127.0374")
            ->assertOk()
            ->assertJsonPath('data.building_name', '역삼빌딩')
            ->assertJsonPath('data.jibun', '서울 강남구 역삼동 701')
            ->assertJsonPath('data.reachable', true);

        $this->actingAs($this->host)->getJson("/api/places/address?plaza_id={$this->plaza->id}&lat=37.60&lng=127.0364")
            ->assertJsonPath('data.reachable', false);
    }

    /** 핀으로 정한 위치(카카오 장소 id 없음)는 좌표로 지도 링크 */
    public function test_pinned_place_without_kakao_id(): void
    {
        $place = ['name' => '역삼공원 정문', 'address' => '서울 강남구 테헤란로 101', 'lat' => 37.5016, 'lng' => 127.0374];
        $this->create($this->plaza, ['place' => $place])->assertCreated()
            ->assertJsonPath('data.place.map_url', 'https://map.kakao.com/link/map/'.rawurlencode('역삼공원 정문').',37.5016,127.0374');
    }

    /** 고른 장소는 참여자에게만: 이름·주소·좌표·카카오맵 링크. 메모(place_detail)는 선택 */
    public function test_picked_place_visible_only_to_members(): void
    {
        $id = $this->create($this->plaza, ['place' => $this->place(37.5016, 127.0374)])->assertCreated()
            ->assertJsonPath('data.place.name', '스타벅스 역삼역점')
            ->assertJsonPath('data.place.map_url', 'https://place.map.kakao.com/12345')
            ->assertJsonPath('data.place_detail', null)
            ->json('data.id');

        $guest = User::factory()->complete()->create();
        $this->actingAs($guest)->getJson("/api/meetups/{$id}")
            ->assertJsonPath('data.place', null)
            ->assertJsonPath('data.area_label', '역삼동 근처')
            ->assertDontSee('스타벅스')
            ->assertDontSee('테헤란로');

        $this->actingAs($guest)->postJson("/api/meetups/{$id}/join")->assertJsonPath('data.place.address', '서울 강남구 테헤란로 101');
    }

    /** 장소 선택도 직접 입력도 없으면 거절. 직접 입력만 있으면 예전처럼 */
    public function test_place_or_manual_detail_required(): void
    {
        $this->create($this->plaza, [])->assertUnprocessable()->assertJsonValidationErrors('place_detail');
        $this->create($this->plaza, ['place_detail' => '역삼역 3번 출구'])->assertCreated()->assertJsonPath('data.place', null);
        $this->create($this->plaza, ['place' => array_merge($this->place(37.5016, 127.0374), ['kakao_id' => 'abc'])])
            ->assertUnprocessable()->assertJsonValidationErrors('place.kakao_id');
    }

    /** 주민 광장: 광장에서 5km 밖 장소는 서버가 거절 (화면을 거치지 않은 요청도) */
    public function test_place_too_far_from_plaza(): void
    {
        $this->create($this->plaza, ['place' => $this->place(37.60, 127.0364)]) // 약 11km 북쪽
            ->assertStatus(422)->assertJson(['code' => 'PLACE_TOO_FAR']);
    }

    /** 동네 광장: 같은 시·군·구 안만 / 자유광장: 제한 없음 */
    public function test_town_and_free_plaza_rules(): void
    {
        [$town] = app(TownService::class)->forLocation(37.5006, 127.0364);

        $this->create($town, ['place' => $this->place(36.35, 127.38)]) // 대전
            ->assertStatus(422)->assertJson(['code' => 'PLACE_TOO_FAR']);
        $this->create($town, ['place' => $this->place((float) $town->lat, (float) $town->lng)])->assertCreated();

        $free = app(TownService::class)->free();
        $this->create($free, ['place' => $this->place(35.1587, 129.1604)])->assertCreated(); // 부산
    }

    public function test_closed_plaza_search(): void
    {
        $this->plaza->update(['status' => Plaza::STATUS_CLOSED]);
        $this->actingAs($this->host)->getJson("/api/places/search?q=카페&plaza_id={$this->plaza->id}")->assertStatus(410);
    }

    /** 공유 미리보기(로그인 없음)에는 고른 장소가 안 나감 */
    public function test_share_preview_hides_place(): void
    {
        $id = $this->create($this->plaza, ['place' => $this->place(37.5016, 127.0374), 'place_detail' => '창가 자리'])->json('data.id');
        $url = $this->actingAs($this->host)->postJson("/api/meetups/{$id}/share")->assertOk()->json('data.url');

        $this->app['auth']->forgetGuards();
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()
            ->assertSee('역삼동 근처')
            ->assertDontSee('스타벅스')
            ->assertDontSee('테헤란로')
            ->assertDontSee('창가 자리');
    }

    /** 카카오 드라이버: 응답 변환·인증 헤더, 실패는 503 (캐시 안 함) */
    public function test_kakao_driver_maps_response(): void
    {
        config(['services.kakao.rest_key' => 'test-key']);
        Http::fake([
            'dapi.kakao.com/*' => Http::response(['documents' => [[
                'id' => '27560699', 'place_name' => '스타벅스 역삼역점', 'category_name' => '음식점 > 카페 > 커피전문점 > 스타벅스',
                'road_address_name' => '서울 강남구 테헤란로 211', 'address_name' => '서울 강남구 역삼동 678-10',
                'x' => '127.0363', 'y' => '37.5008', 'distance' => '42',
            ]]]),
        ]);

        $r = (new KakaoPlaceSearch)->search('스타벅스', 37.5006, 127.0364, 5000);

        $this->assertSame('27560699', $r[0]['kakao_id']);
        $this->assertSame('스타벅스', $r[0]['category']);
        $this->assertSame('서울 강남구 테헤란로 211', $r[0]['address']);
        $this->assertSame('서울 강남구 역삼동 678-10', $r[0]['jibun']);
        $this->assertSame(42, $r[0]['distance_m']);
        Http::assertSent(fn (HttpRequest $req) => $req->hasHeader('Authorization', 'KakaoAK test-key')
            && $req['x'] == 127.0364 && $req['y'] == 37.5006 && $req['radius'] == 5000 && $req['sort'] === 'distance');
    }

    /** 가게 이름으로 못 찾으면 주소 검색으로 한 번 더 (장소 id 없음, 거리는 직접 계산) */
    public function test_kakao_falls_back_to_address_search(): void
    {
        Http::fake([
            'dapi.kakao.com/v2/local/search/keyword.json*' => Http::response(['documents' => []]),
            'dapi.kakao.com/v2/local/search/address.json*' => Http::response(['documents' => [[
                'address_name' => '서울 강남구 역삼동 678-10', 'x' => '127.0363', 'y' => '37.5008',
                'address' => ['address_name' => '서울 강남구 역삼동 678-10'],
                'road_address' => ['address_name' => '서울 강남구 테헤란로 211', 'building_name' => ''],
            ]]]),
        ]);

        $r = (new KakaoPlaceSearch)->search('테헤란로 211', 37.5006, 127.0364, 5000);

        $this->assertNull($r[0]['kakao_id']);
        $this->assertSame('서울 강남구 테헤란로 211', $r[0]['name']);
        $this->assertSame('서울 강남구 역삼동 678-10', $r[0]['jibun']);
        $this->assertLessThan(100, $r[0]['distance_m']);
    }

    public function test_kakao_reverse_maps_response(): void
    {
        Http::fake([
            'dapi.kakao.com/v2/local/geo/coord2address.json*' => Http::response(['documents' => [[
                'road_address' => ['address_name' => '서울 강남구 테헤란로 211', 'building_name' => '한국고등교육재단빌딩'],
                'address' => ['address_name' => '서울 강남구 역삼동 678-10'],
            ]]]),
        ]);

        $r = (new KakaoPlaceSearch)->reverse(37.5008, 127.0363);

        $this->assertSame('서울 강남구 테헤란로 211', $r['address']);
        $this->assertSame('서울 강남구 역삼동 678-10', $r['jibun']);
        $this->assertSame('한국고등교육재단빌딩', $r['building_name']);
    }

    public function test_kakao_driver_failure_is_503(): void
    {
        Http::fake(['dapi.kakao.com/*' => Http::response([], 401)]);

        try {
            (new KakaoPlaceSearch)->search('카페', 37.5, 127.0, 5000);
            $this->fail('예외가 나야 함');
        } catch (\App\Exceptions\DomainException $e) {
            $this->assertSame('PLACE_SEARCH_UNAVAILABLE', $e->errorCode);
            $this->assertSame(503, $e->status);
        }
    }
}
