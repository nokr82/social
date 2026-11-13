<?php

namespace App\Services\Region;

/** 좌표 → 동네(드라이버에 따라 시·군·구 또는 법정동). 찾지 못하면 null (바다 위·해외 등) */
interface RegionResolver
{
    public function resolve(float $lat, float $lng): ?Region;
}
