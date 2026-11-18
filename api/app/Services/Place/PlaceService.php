<?php

namespace App\Services\Place;

use App\Exceptions\DomainException;
use App\Models\Plaza;
use App\Services\Region\RegionResolver;
use App\Support\Geo;

/**
 * 번개 장소: 검색 범위와 '이 광장 번개로 열 수 있는 장소인가' 판단을 한 곳에.
 * 광장 종류마다 기준이 다르다:
 * - 주민 광장 : 광장에서 config social.meetup.place_max_m(5km) 안 — 광장 사람들끼리 근처에서 만나는 기능
 * - 동네 광장 : 같은 시·군·구 안 (광장 좌표는 첫 입장자 근처 칸이라 거리 기준이 안 맞음)
 * - 자유광장  : 제한 없음 (전국 광장)
 * 화면이 거른 결과만 보내더라도, 생성 때 서버가 다시 확인한다 (좌표 조작 방지)
 */
class PlaceService
{
    public function __construct(
        private readonly PlaceSearch $search,
        private readonly RegionResolver $regions,
    ) {}

    /**
     * @param  float|null  $lat,$lng  내 현재 좌표(진짜 위치일 때만 화면이 보냄). 동네·자유광장 검색 중심용
     */
    public function search(Plaza $plaza, string $query, ?float $lat, ?float $lng): array
    {
        if ($plaza->status !== Plaza::STATUS_OPEN) {
            throw new DomainException('PLAZA_CLOSED', '닫힌 광장이에요', 410);
        }

        $max = (int) config('social.meetup.place_max_m');
        if ($plaza->isFree()) {
            $results = $this->search->search($query, $lat, $lng, null);
        } elseif ($plaza->isTown()) {
            // 내 위치가 없으면 광장 좌표 근처부터. 동네 밖 결과는 아래에서 거름
            [$cLat, $cLng] = $lat !== null ? [$lat, $lng] : [(float) $plaza->lat, (float) $plaza->lng];
            $results = $this->search->search($query, $cLat, $cLng, 20000);
        } else {
            $results = $this->search->search($query, (float) $plaza->lat, (float) $plaza->lng, $max);
        }

        return collect($results)
            ->filter(fn (array $p) => $this->reachable($plaza, $p['lat'], $p['lng']))
            ->map(fn (array $p) => $p + ['distance_label' => $p['distance_m'] !== null ? $this->label($p['distance_m']) : null])
            ->values()->all();
    }

    /** 번개 생성 때 서버 재확인 */
    public function assertReachable(Plaza $plaza, float $lat, float $lng): void
    {
        if (! $this->reachable($plaza, $lat, $lng)) {
            $msg = $plaza->isTown() ? '이 동네 광장 번개는 같은 동네 안 장소만 고를 수 있어요' : '광장에서 5km 안의 장소만 고를 수 있어요';
            throw new DomainException('PLACE_TOO_FAR', $msg, 422);
        }
    }

    private function reachable(Plaza $plaza, float $lat, float $lng): bool
    {
        if ($plaza->isFree()) {
            return true;
        }
        if ($plaza->isTown()) {
            return $this->regions->resolve($lat, $lng)?->code === $plaza->region_code;
        }

        return Geo::distanceM((float) $plaza->lat, (float) $plaza->lng, $lat, $lng) <= (int) config('social.meetup.place_max_m');
    }

    /** 장소 거리는 정확해도 됨(사람 위치가 아님): 1km 미만 m, 이상 소수 한 자리 km */
    private function label(int $m): string
    {
        return $m < 1000 ? "{$m}m" : number_format($m / 1000, 1).'km';
    }
}
