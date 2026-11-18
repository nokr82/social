<?php

namespace App\Services\Place;

use App\Support\Geo;

/**
 * 개발·테스트용: 카카오 호출 없이 검색 위치 근처에 가짜 장소 2개.
 * (운영에서는 AppServiceProvider가 부팅을 막음)
 */
class FakePlaceSearch implements PlaceSearch
{
    public function search(string $query, ?float $lat, ?float $lng, ?int $radiusM): array
    {
        $lat ??= 37.5006;
        $lng ??= 127.0364;

        return collect([[0.001, 0.001, '1'], [0.003, -0.002, '2']])->map(function ($o) use ($query, $lat, $lng) {
            [$pLat, $pLng] = [$lat + $o[0], $lng + $o[1]];

            return [
                'kakao_id' => '9000000'.$o[2],
                'name' => "{$query} {$o[2]}호점",
                'category' => '카페',
                'address' => '서울 강남구 테헤란로 '.(100 + (int) $o[2]),
                'jibun' => '서울 강남구 역삼동 '.(700 + (int) $o[2]),
                'lat' => $pLat,
                'lng' => $pLng,
                'distance_m' => (int) round(Geo::distanceM($lat, $lng, $pLat, $pLng)),
            ];
        })->all();
    }

    public function reverse(float $lat, float $lng): ?array
    {
        return ['address' => '서울 강남구 테헤란로 101', 'jibun' => '서울 강남구 역삼동 701', 'building_name' => '역삼빌딩'];
    }
}
