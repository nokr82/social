<?php

namespace App\Support;

/**
 * 위치 유틸: geohash 인코딩/디코딩, 거리 계산, 거리 라벨.
 *
 * geohash 원리: 지구를 위도·경도로 번갈아 반씩 나누며 0/1을 기록하고,
 * 5비트씩 묶어 base32 문자로 바꾼다. 글자 수가 늘수록 칸이 작아진다.
 *   6자리 ≈ 1.2km x 0.6km, 7자리 ≈ 153m x 153m
 * → "어느 칸에 있다"만 저장해 정확한 위치를 남기지 않는다.
 */
final class Geo
{
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    public static function encode(float $lat, float $lng, int $precision): string
    {
        [$latMin, $latMax, $lngMin, $lngMax] = [-90.0, 90.0, -180.0, 180.0];
        $hash = '';
        $bit = 0;
        $ch = 0;
        $evenBit = true; // 짝수 번째 비트는 경도, 홀수는 위도

        while (strlen($hash) < $precision) {
            if ($evenBit) {
                $mid = ($lngMin + $lngMax) / 2;
                if ($lng >= $mid) { $ch = ($ch << 1) | 1; $lngMin = $mid; } else { $ch <<= 1; $lngMax = $mid; }
            } else {
                $mid = ($latMin + $latMax) / 2;
                if ($lat >= $mid) { $ch = ($ch << 1) | 1; $latMin = $mid; } else { $ch <<= 1; $latMax = $mid; }
            }
            $evenBit = ! $evenBit;

            if (++$bit === 5) {
                $hash .= self::BASE32[$ch];
                $bit = 0;
                $ch = 0;
            }
        }

        return $hash;
    }

    /**
     * geohash 칸의 중심 좌표.
     *
     * @return array{0: float, 1: float} [lat, lng]
     */
    public static function center(string $hash): array
    {
        [$latMin, $latMax, $lngMin, $lngMax] = [-90.0, 90.0, -180.0, 180.0];
        $evenBit = true;

        foreach (str_split($hash) as $c) {
            $idx = strpos(self::BASE32, $c);
            for ($n = 4; $n >= 0; $n--) {
                $bitN = ($idx >> $n) & 1;
                if ($evenBit) {
                    $mid = ($lngMin + $lngMax) / 2;
                    $bitN ? $lngMin = $mid : $lngMax = $mid;
                } else {
                    $mid = ($latMin + $latMax) / 2;
                    $bitN ? $latMin = $mid : $latMax = $mid;
                }
                $evenBit = ! $evenBit;
            }
        }

        return [round(($latMin + $latMax) / 2, 6), round(($lngMin + $lngMax) / 2, 6)];
    }

    /** 두 좌표 사이 거리(m). 하버사인 공식 */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /**
     * 반경 검색용 바운딩 박스. DB 인덱스로 후보를 좁힌 뒤 정확한 거리는 PHP에서 계산.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} [minLat, maxLat, minLng, maxLng]
     */
    public static function boundingBox(float $lat, float $lng, float $radiusM): array
    {
        $dLat = $radiusM / 111320;
        $dLng = $radiusM / (111320 * max(cos(deg2rad($lat)), 0.01));

        return [$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng];
    }

    /** 정밀 거리를 범위 라벨로 바꾼다 (config/social.php distance_buckets) */
    public static function distanceLabel(float $meters): ?string
    {
        foreach (config('social.distance_buckets') as [$max, $label]) {
            if ($meters <= $max) {
                return $label;
            }
        }

        return null;
    }
}
