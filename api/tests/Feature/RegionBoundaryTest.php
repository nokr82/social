<?php

namespace Tests\Feature;

use App\Models\RegionBoundary;
use App\Models\User;
use App\Services\Region\BoundaryRegionResolver;
use App\Services\Region\RegionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 자체 경계(시·군·구) 드라이버: 적재 명령, 이름 규칙, 안/밖 판정, 구멍, 해안 보정, 캐시 무효화 */
class RegionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'rb').'.geojson';
        $this->app->instance(RegionResolver::class, new BoundaryRegionResolver);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /** 사각형 고리 [lng,lat] (GeoJSON 순서) */
    private static function box(float $lng1, float $lat1, float $lng2, float $lat2): array
    {
        return [[$lng1, $lat1], [$lng2, $lat1], [$lng2, $lat2], [$lng1, $lat2], [$lng1, $lat1]];
    }

    /** admdongkor 원본과 같은 모양: 머리말 + '한 줄에 한 동' */
    private function writeFixture(string $gangnamName = '강남구'): void
    {
        $f = fn ($sgg, $sido, $sgg_nm, $adm, $coords) => json_encode(['type' => 'Feature', 'properties' => [
            'adm_nm' => "{$sido} {$sgg_nm} 어떤동", 'adm_cd2' => $adm, 'sgg' => $sgg, 'sido' => substr($sgg, 0, 2),
            'sidonm' => $sido, 'sggnm' => $sgg_nm, 'adm_cd' => '0',
        ], 'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $coords]], JSON_UNESCAPED_UNICODE);

        $features = [
            // 강남구: 큰 사각형 + 가운데 구멍 (구멍 자리는 서초구)
            $f('11680', '서울특별시', $gangnamName, '1168000000', [[self::box(127.00, 37.45, 127.10, 37.55), self::box(127.03, 37.49, 127.04, 37.51)]]),
            $f('11650', '서울특별시', '서초구', '1165000000', [[self::box(127.03, 37.49, 127.04, 37.51)]]),
            // 이름이 겹치는 구 → 시·도 짧은 이름을 붙임
            $f('11140', '서울특별시', '중구', '1114000000', [[self::box(126.97, 37.55, 126.99, 37.57)]]),
            $f('26110', '부산광역시', '중구', '2611000000', [[self::box(129.02, 35.09, 129.04, 35.11)]]),
            // 일반구는 띄어쓰기
            $f('41281', '경기도', '고양시덕양구', '4128100000', [[self::box(126.80, 37.62, 126.86, 37.66)]]),
        ];

        file_put_contents($this->file, "{\n\"type\": \"FeatureCollection\",\n\"features\": [\n"
            .implode(",\n", $features)."\n]\n}\n");
    }

    private function resolve(float $lat, float $lng): ?string
    {
        return app(RegionResolver::class)->resolve($lat, $lng)?->name;
    }

    public function test_import_names_regions(): void
    {
        $this->writeFixture();

        $this->artisan('regions:import', ['--file' => $this->file])->assertSuccessful();

        $this->assertSame(5, RegionBoundary::count());
        $names = RegionBoundary::pluck('name', 'code')->all();
        $this->assertSame('강남구', $names['SGG-11680']);
        $this->assertSame('서울 중구', $names['SGG-11140']);
        $this->assertSame('부산 중구', $names['SGG-26110']);
        $this->assertSame('고양시 덕양구', $names['SGG-41281']);
    }

    public function test_point_in_polygon_with_hole_and_coast_correction(): void
    {
        $this->writeFixture();
        $this->artisan('regions:import', ['--file' => $this->file]);

        $this->assertSame('강남구', $this->resolve(37.47, 127.01));
        $this->assertSame('서초구', $this->resolve(37.50, 127.035));  // 강남구의 구멍 안 → 서초구
        $this->assertSame('부산 중구', $this->resolve(35.10, 129.03));
        $this->assertSame('강남구', $this->resolve(37.47, 127.103));  // 경계 밖 약 270m → 가까운 구로 보정
        $this->assertNull($this->resolve(34.0, 126.0));              // 먼바다
    }

    public function test_town_api_uses_district_and_reimport_refreshes_cache(): void
    {
        $this->writeFixture();
        $this->artisan('regions:import', ['--file' => $this->file]);
        $u = User::factory()->complete()->create();

        $this->actingAs($u)->getJson('/api/town?lat=37.47&lng=127.01')
            ->assertOk()->assertJsonPath('data.name', '강남구 광장')->assertJsonPath('data.region_name', '강남구');

        // 경계 갱신 → 버전 키가 바뀌어 같은 칸의 예전 판정 캐시를 쓰지 않음
        $this->writeFixture('새이름구');
        $this->artisan('regions:import', ['--file' => $this->file]);
        $this->assertSame('새이름구', $this->resolve(37.47, 127.01));
    }

    /** 적재를 잊으면 '동네 없음'으로 조용히 숨기지 않고 503 + 로그 */
    public function test_empty_boundaries_is_an_error_not_cached(): void
    {
        $u = User::factory()->complete()->create();

        $this->actingAs($u)->getJson('/api/town?lat=37.47&lng=127.01')
            ->assertStatus(503)->assertJsonPath('code', 'REGION_UNAVAILABLE');

        $this->writeFixture();
        $this->artisan('regions:import', ['--file' => $this->file]);
        $this->actingAs($u)->getJson('/api/town?lat=37.47&lng=127.01')->assertOk();
    }

    public function test_import_rejects_unknown_format_and_keeps_old_data(): void
    {
        $this->writeFixture();
        $this->artisan('regions:import', ['--file' => $this->file]);

        file_put_contents($this->file, json_encode(['type' => 'FeatureCollection', 'features' => []]));
        $this->artisan('regions:import', ['--file' => $this->file])->assertFailed();

        $this->assertSame(5, RegionBoundary::count());
    }
}
