<?php

namespace App\Services\Region;

/** 좌표 → 동네(법정동). 찾지 못하면 null (바다 위·해외 등) */
interface RegionResolver
{
    public function resolve(float $lat, float $lng): ?Region;
}
