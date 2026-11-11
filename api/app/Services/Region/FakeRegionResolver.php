<?php

namespace App\Services\Region;

use App\Support\Geo;

/**
 * 개발·테스트용: 카카오 API 없이 geohash 5자리 칸(약 5km)을 동네로 취급.
 * 개발 기본 위치(역삼역)에서 화면이 디자인과 같게 보이도록 이름은 '역삼동'.
 */
class FakeRegionResolver implements RegionResolver
{
    public function resolve(float $lat, float $lng): ?Region
    {
        return new Region('FAKE-'.Geo::encode($lat, $lng, 5), '역삼동');
    }
}
