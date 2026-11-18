<?php

namespace App\Services\Place;

/**
 * 장소 검색 드라이버 (config social.drivers.place: kakao | fake).
 * 반환: [['kakao_id', 'name', 'category', 'address', 'lat', 'lng', 'distance_m'|null], ...]
 * - 좌표($lat,$lng)가 있으면 가까운 순, $radiusM 안에서만
 */
interface PlaceSearch
{
    public function search(string $query, ?float $lat, ?float $lng, ?int $radiusM): array;
}
