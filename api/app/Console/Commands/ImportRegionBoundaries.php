<?php

namespace App\Console\Commands;

use App\Models\RegionBoundary;
use App\Services\Region\BoundaryRegionResolver;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 동네 경계 적재: vuski/admdongkor 행정동 GeoJSON → region_boundaries.
 *
 * 사용: php artisan regions:import                 (config의 URL에서 내려받아 적재)
 *       php artisan regions:import --file=경로.geojson (받아 둔 파일로 적재)
 * 갱신: 새 버전이 나오면 config/social.php의 region_boundary.url 날짜만 바꾸고 다시 실행 (통째로 교체, 몇십 초)
 *
 * 메모리: 35MB 파일을 한 번에 json_decode하면 수백 MB가 든다. 원본은 '한 줄 = 행정동 1개'라서
 *         줄 단위로 읽어 한 동씩 처리한다 (2GB 서버에서도 안전).
 */
class ImportRegionBoundaries extends Command
{
    protected $signature = 'regions:import {--file= : 받아 둔 GeoJSON 경로 (생략하면 내려받음)}';

    protected $description = '동네(시·군·구) 경계 데이터를 적재합니다';

    /** 시·도 짧은 이름: 이름이 겹치는 구('중구'가 6곳 등)에만 앞에 붙인다 */
    private const SIDO_SHORT = [
        '서울특별시' => '서울', '부산광역시' => '부산', '대구광역시' => '대구', '인천광역시' => '인천',
        '광주광역시' => '광주', '대전광역시' => '대전', '울산광역시' => '울산', '세종특별자치시' => '세종',
        '경기도' => '경기', '강원특별자치도' => '강원', '강원도' => '강원', '충청북도' => '충북', '충청남도' => '충남',
        '전북특별자치도' => '전북', '전라북도' => '전북', '전라남도' => '전남', '경상북도' => '경북', '경상남도' => '경남',
        '제주특별자치도' => '제주', '전남광주통합특별시' => '광주',
    ];

    public function handle(): int
    {
        $path = $this->option('file') ?: $this->download();
        if (! is_readable($path)) {
            $this->error("파일을 읽을 수 없어요: {$path}");

            return self::FAILURE;
        }

        // 1차: 시·군·구 목록만 모아 '이름 겹침'을 먼저 알아낸다 (이름을 정해야 2차에서 저장 가능)
        $sggs = [];
        $count = 0;
        foreach ($this->features($path) as $p) {
            $sggs[$p['sgg']] = [$p['sidonm'], $p['sggnm']];
            $count++;
        }
        if ($count === 0) {
            $this->error('행정동을 하나도 못 읽었어요. admdongkor 원본(한 줄에 한 동) GeoJSON인지 확인하세요.');

            return self::FAILURE;
        }
        $nameCount = array_count_values(array_column($sggs, 1));

        // 2차: 저장. 실패하면 기존 데이터가 그대로 남도록 한 트랜잭션으로 통째 교체
        DB::transaction(function () use ($path, $sggs, $nameCount) {
            RegionBoundary::query()->delete();
            $batch = [];
            foreach ($this->features($path, withGeometry: true) as $p) {
                [$sidonm, $sggnm] = $sggs[$p['sgg']];
                $batch[] = $this->row($p, $this->displayName($sidonm, $sggnm, $nameCount[$sggnm] > 1));
                if (count($batch) === 100) { // 행이 커서(경계 좌표) 작게 끊어 넣는다
                    RegionBoundary::insert($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                RegionBoundary::insert($batch);
            }
        });

        // 캐시 키에 버전이 들어가므로 이 값만 바꾸면 예전 판정 결과는 전부 무시된다
        Cache::forever(BoundaryRegionResolver::VERSION_KEY, now()->timestamp);

        $this->info("적재 완료: 행정동 {$count}개 · 시군구 ".count($sggs).'개');
        $this->line('출처 표시 필요: 통계청 SGIS 행정동 경계(공공누리 1유형), 가공 vuski/admdongkor (CC BY 4.0)');

        return self::SUCCESS;
    }

    /** config의 URL에서 임시 파일로 내려받기 (스트리밍 저장 → 메모리에 안 올림) */
    private function download(): string
    {
        $url = config('social.region_boundary.url');
        $tmp = storage_path('app/region-boundaries.geojson');
        $this->info("내려받는 중: {$url}");
        Http::timeout(300)->sink($tmp)->get($url)->throw();

        return $tmp;
    }

    /**
     * GeoJSON을 줄 단위로 읽어 행정동 속성(+경계)을 하나씩 돌려준다.
     *
     * @return Generator<array{sgg: string, sidonm: string, sggnm: string, adm_cd2: string, geometry?: array}>
     */
    private function features(string $path, bool $withGeometry = false): Generator
    {
        $fh = fopen($path, 'r');
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim(trim($line), ',');
                if (! str_starts_with($line, '{') || ! str_contains($line, '"Feature"')) {
                    continue; // 머리말(FeatureCollection, crs 등)·닫는 괄호
                }
                $f = json_decode($line, true);
                $p = $f['properties'] ?? [];
                if (empty($p['sgg']) || empty($p['sggnm']) || empty($f['geometry'])) {
                    throw new RuntimeException('형식이 다른 행: '.mb_substr($line, 0, 120));
                }
                yield [
                    'sgg' => (string) $p['sgg'],
                    'sidonm' => (string) $p['sidonm'],
                    'sggnm' => (string) $p['sggnm'],
                    'adm_cd2' => (string) ($p['adm_cd2'] ?? ''),
                ] + ($withGeometry ? ['geometry' => $f['geometry']] : []);
            }
        } finally {
            fclose($fh);
        }
    }

    /** 경계를 [다각형[고리[lng,lat,...]]]로 평탄화 + 소수 5자리(≈1m) 반올림 + 외곽 사각형 계산 */
    private function row(array $p, string $name): array
    {
        $g = $p['geometry'];
        $polygons = $g['type'] === 'Polygon' ? [$g['coordinates']] : $g['coordinates'];
        [$minLat, $maxLat, $minLng, $maxLng] = [INF, -INF, INF, -INF];
        $out = [];
        foreach ($polygons as $polygon) {
            $rings = [];
            foreach ($polygon as $ring) {
                $flat = [];
                foreach ($ring as [$lng, $lat]) {
                    $flat[] = round($lng, 5);
                    $flat[] = round($lat, 5);
                    [$minLat, $maxLat] = [min($minLat, $lat), max($maxLat, $lat)];
                    [$minLng, $maxLng] = [min($minLng, $lng), max($maxLng, $lng)];
                }
                $rings[] = $flat;
            }
            $out[] = $rings;
        }

        return [
            'code' => 'SGG-'.$p['sgg'],
            'name' => $name,
            'dong_code' => mb_substr($p['adm_cd2'], 0, 10),
            'min_lat' => $minLat, 'max_lat' => $maxLat,
            'min_lng' => $minLng, 'max_lng' => $maxLng,
            'rings' => json_encode($out),
        ];
    }

    /**
     * 화면 이름. '고양시덕양구' → '고양시 덕양구', 이름이 다른 시·도와 겹치면 '부산 중구'.
     */
    private function displayName(string $sidonm, string $sggnm, bool $duplicated): string
    {
        $name = preg_replace('/^(\S+시)(\S+구)$/u', '$1 $2', $sggnm);
        if ($duplicated) {
            $name = (self::SIDO_SHORT[$sidonm] ?? mb_substr($sidonm, 0, 2)).' '.$name;
        }

        return $name;
    }
}
