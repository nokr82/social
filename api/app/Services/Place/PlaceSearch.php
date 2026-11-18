<?php

namespace App\Services\Place;

/**
 * 장소 검색 드라이버 (config social.drivers.place: kakao | fake).
 * 반환: [['kakao_id', 'name', 'category', 'address', 'lat', 'lng', 'distance_m'|null], ...]
 * - 좌표($lat,$lng)가 있으면 가까운 순, $radiusM 안에서만
 * reverse(): 지도 핀 위치 → 주소. ['address'(도로명 우선), 'jibun', 'building_name'] 또는 null(바다 등)
 */
interface PlaceSearch
{
    public function search(string $query, ?float $lat, ?float $lng, ?int $radiusM): array;

    public function reverse(float $lat, float $lng): ?array;
}
