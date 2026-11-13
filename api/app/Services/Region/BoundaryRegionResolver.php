<?php

namespace App\Services\Region;

use App\Exceptions\DomainException;
use App\Models\RegionBoundary;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 좌표 → 시·군·구. 외부 API·키·과금 없이 서버에 적재한 경계(region_boundaries)로 직접 판단한다.
 *
 * 원리: 종이 지도에 핀을 꽂고 어느 구역 선 안쪽인지 보는 것과 같다.
 *  1) 외곽 사각형(min/max 위경도)에 점이 들어가는 행정동만 DB에서 고른다 (보통 1~4개)
 *  2) 그 다각형들에 대해 '광선 투사법'으로 안/밖 판정: 점에서 오른쪽으로 선을 그어 경계를 홀수 번 넘으면 안
 *  3) 어디에도 안 들어가면(해안가 GPS 오차 등) 500m 안의 가장 가까운 동으로 보정
 *
 * 결과는 geohash 7자리 칸(약 150m)마다 30일 캐시. 경계를 다시 적재하면 버전 키가 바뀌어 캐시가 자동으로 무효화된다.
 */
class BoundaryRegionResolver implements RegionResolver
{
    public const VERSION_KEY = 'region:boundary_version';

    /** 해안·경계 오차 보정 반경(도). 위도 0.005° ≈ 550m */
    private const NEAR_DEG = 0.005;

    public function resolve(float $lat, float $lng): ?Region
    {
        $ver = Cache::get(self::VERSION_KEY, 0);
        $cell = Geo::encode($lat, $lng, 7);

        $cached = Cache::remember("region:b{$ver}:{$cell}", now()->addDays(30), function () use ($lat, $lng) {
            $hit = $this->containing($lat, $lng) ?? $this->nearest($lat, $lng);
            if ($hit) {
                return ['code' => $hit->code, 'name' => $hit->name];
            }
            // 경계 데이터를 아예 안 넣은 상태면 '동네 없음'으로 숨기지 말고 오류로 드러낸다 (캐시도 안 됨)
            if (! RegionBoundary::query()->exists()) {
                Log::error('region_boundaries 비어 있음: php artisan regions:import 실행 필요');
                throw new DomainException('REGION_UNAVAILABLE', '동네 정보를 불러오지 못했어요. 잠시 후 다시 시도해 주세요', 503);
            }

            return ['none' => true]; // 바다 위·해외: 결과 없음도 캐시 (반복 계산 방지)
        });

        return isset($cached['code']) ? new Region($cached['code'], $cached['name']) : null;
    }

    /** 점을 품은 행정동 (경계선 위의 점은 먼저 찾은 쪽) */
    private function containing(float $lat, float $lng): ?RegionBoundary
    {
        foreach ($this->candidates($lat, $lng, 0) as $b) {
            foreach (json_decode($b->rings, true) as $polygon) {
                if (self::inPolygon($polygon, $lng, $lat)) {
                    return $b;
                }
            }
        }

        return null;
    }

    /** NEAR_DEG 안에서 경계선이 가장 가까운 행정동 (해안선 근처 GPS 오차 보정) */
    private function nearest(float $lat, float $lng): ?RegionBoundary
    {
        $kx = cos(deg2rad($lat)); // 경도 1°는 위도 1°보다 짧다 → 경도에 곱해 평면 거리로 비교
        $best = null;
        $bestD = self::NEAR_DEG ** 2;

        foreach ($this->candidates($lat, $lng, self::NEAR_DEG) as $b) {
            foreach (json_decode($b->rings, true) as $polygon) {
                foreach ($polygon as $ring) {
                    for ($i = 2, $n = count($ring); $i < $n; $i += 2) {
                        $d = self::segmentDistSq(
                            $lng * $kx, $lat,
                            $ring[$i - 2] * $kx, $ring[$i - 1],
                            $ring[$i] * $kx, $ring[$i + 1],
                        );
                        if ($d < $bestD) {
                            [$best, $bestD] = [$b, $d];
                        }
                    }
                }
            }
        }

        return $best;
    }

    /** 점 P와 선분 AB 사이 최단거리의 제곱 (선분 위 가장 가까운 점으로 수선의 발, 범위를 벗어나면 끝점) */
    private static function segmentDistSq(float $px, float $py, float $ax, float $ay, float $bx, float $by): float
    {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len = $dx * $dx + $dy * $dy;
        $t = $len > 0 ? max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len)) : 0;
        $ex = $ax + $t * $dx - $px;
        $ey = $ay + $t * $dy - $py;

        return $ex * $ex + $ey * $ey;
    }

    private function candidates(float $lat, float $lng, float $pad)
    {
        return RegionBoundary::query()
            ->where('min_lat', '<=', $lat + $pad)->where('max_lat', '>=', $lat - $pad)
            ->where('min_lng', '<=', $lng + $pad)->where('max_lng', '>=', $lng - $pad)
            ->get(['id', 'code', 'name', 'rings']);
    }

    /**
     * 광선 투사법(짝홀 규칙). 다각형의 모든 고리(바깥 경계 + 구멍)를 같은 카운터로 세면
     * 구멍 안의 점은 짝수 번 넘게 되어 자동으로 '밖'이 된다.
     *
     * @param  array<int, array<int, float>>  $polygon  고리 목록, 고리 = [lng,lat,lng,lat,...]
     */
    public static function inPolygon(array $polygon, float $x, float $y): bool
    {
        $inside = false;
        foreach ($polygon as $ring) {
            $n = count($ring);
            for ($i = 0, $j = $n - 2; $i < $n; $j = $i, $i += 2) {
                $xi = $ring[$i];
                $yi = $ring[$i + 1];
                $xj = $ring[$j];
                $yj = $ring[$j + 1];
                // 변(i-j)이 점의 수평선을 가로지르고, 교차점이 점보다 오른쪽이면 1번 넘은 것
                if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }
}
