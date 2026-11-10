<?php

namespace Tests\Unit;

use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function test_geohash_known_value(): void
    {
        // 위키백과 예제: (57.64911, 10.40744) → u4pruydqqvj
        $this->assertSame('u4pruydqqvj', Geo::encode(57.64911, 10.40744, 11));
    }

    public function test_center_is_inside_cell(): void
    {
        $hash = Geo::encode(37.5006, 127.0364, 7);
        [$lat, $lng] = Geo::center($hash);

        $this->assertSame($hash, Geo::encode($lat, $lng, 7));
        $this->assertLessThan(120, Geo::distanceM(37.5006, 127.0364, $lat, $lng));
    }

    public function test_distance(): void
    {
        // 강남역 ↔ 역삼역 약 0.8km
        $this->assertEqualsWithDelta(800, Geo::distanceM(37.4979, 127.0276, 37.5006, 127.0364), 100);
    }
}
