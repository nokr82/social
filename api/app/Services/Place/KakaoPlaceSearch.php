<?php

namespace App\Services\Place;

use App\Exceptions\DomainException;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 카카오 로컬 '키워드로 장소 검색' (REST API 키, 카카오 로그인과 같은 앱).
 * - 키를 화면에 노출하지 않으려고 서버를 거친다 (호출 횟수 제한도 서버에서)
 * - 같은 검색어·같은 동네(geohash6 ≈ 1.2km 칸)는 10분 캐시 → 여러 사람이 같은 걸 찾아도 한 번만 호출
 * - 키 오류·한도 초과는 캐시하지 않고 503으로 (화면은 '직접 입력'으로 계속 진행 가능)
 */
class KakaoPlaceSearch implements PlaceSearch
{
    private const URL = 'https://dapi.kakao.com/v2/local/search/keyword.json';
    private const ADDRESS_URL = 'https://dapi.kakao.com/v2/local/search/address.json';
    private const REVERSE_URL = 'https://dapi.kakao.com/v2/local/geo/coord2address.json';

    public function search(string $query, ?float $lat, ?float $lng, ?int $radiusM): array
    {
        $cell = $lat !== null ? Geo::encode($lat, $lng, 6) : 'all';
        $key = 'place:kw:'.md5(mb_strtolower($query)."|{$cell}|{$radiusM}");

        return Cache::remember($key, now()->addMinutes(10), function () use ($query, $lat, $lng, $radiusM) {
            $params = ['query' => $query, 'size' => 15];
            if ($lat !== null && $lng !== null) {
                // 카카오는 x=경도, y=위도. radius 최대 20000m
                $params += ['x' => $lng, 'y' => $lat, 'sort' => 'distance'];
                if ($radiusM) {
                    $params['radius'] = min($radiusM, 20000);
                }
            }

            $res = Http::withHeaders(['Authorization' => 'KakaoAK '.config('services.kakao.rest_key')])
                ->timeout(3)
                ->get(self::URL, $params);

            if (! $res->successful()) {
                Log::warning('kakao keyword search failed', ['status' => $res->status()]);
                throw new DomainException('PLACE_SEARCH_UNAVAILABLE', '장소 검색이 잠시 안 돼요. 직접 입력해 주세요', 503);
            }

            $docs = $res->json('documents', []);
            // 가게 이름으로 못 찾으면 주소('테헤란로 211', '역삼동 678')로 한 번 더 — 사용자는 둘을 구분하지 않고 입력함
            if (! $docs) {
                return $this->searchAddress($query, $lat, $lng);
            }

            return collect($docs)->map(fn (array $d) => [
                'kakao_id' => (string) $d['id'],
                'name' => (string) $d['place_name'],
                // '음식점 > 술집 > 호프,요리주점' → 마지막 단계만
                'category' => trim((string) collect(explode('>', (string) ($d['category_name'] ?? '')))->last()),
                'address' => (string) (($d['road_address_name'] ?? '') ?: ($d['address_name'] ?? '')),
                // 지번 주소: 동 이름 자동 채우기용 (도로명엔 동 이름이 없음)
                'jibun' => (string) ($d['address_name'] ?? ''),
                'lat' => (float) $d['y'],
                'lng' => (float) $d['x'],
                'distance_m' => ($d['distance'] ?? '') !== '' ? (int) $d['distance'] : null,
            ])->values()->all();
        });
    }

    /** 주소 검색 결과를 장소 형식으로 (가게 id 없음 → kakao_id null, 이름은 주소) */
    private function searchAddress(string $query, ?float $lat, ?float $lng): array
    {
        $res = Http::withHeaders(['Authorization' => 'KakaoAK '.config('services.kakao.rest_key')])
            ->timeout(3)
            ->get(self::ADDRESS_URL, ['query' => $query, 'size' => 10]);

        if (! $res->successful()) {
            Log::warning('kakao address search failed', ['status' => $res->status()]);
            throw new DomainException('PLACE_SEARCH_UNAVAILABLE', '장소 검색이 잠시 안 돼요. 지도에서 핀으로 골라 주세요', 503);
        }

        return collect($res->json('documents', []))->map(function (array $d) use ($lat, $lng) {
            $road = (string) ($d['road_address']['address_name'] ?? '');
            $jibun = (string) ($d['address']['address_name'] ?? $d['address_name'] ?? '');
            [$pLat, $pLng] = [(float) $d['y'], (float) $d['x']];

            return [
                'kakao_id' => null,
                'name' => (string) (($d['road_address']['building_name'] ?? '') ?: ($road ?: $jibun)),
                'category' => '주소',
                'address' => $road ?: $jibun,
                'jibun' => $jibun,
                'lat' => $pLat,
                'lng' => $pLng,
                // 주소 검색은 거리를 안 주므로 직접 계산 (정렬·표시용)
                'distance_m' => $lat !== null ? (int) round(Geo::distanceM($lat, $lng, $pLat, $pLng)) : null,
            ];
        })->sortBy(fn ($p) => $p['distance_m'] ?? 0)->values()->all();
    }

    /**
     * 지도 핀 위치 → 주소 (카카오 '좌표로 주소 변환').
     * 지도를 끌 때마다 불리므로 geohash8 칸(약 38×19m)별 1일 캐시 — 핀을 조금씩 옮겨도 같은 칸이면 호출 없음
     */
    public function reverse(float $lat, float $lng): ?array
    {
        $cached = Cache::remember('place:rev:'.Geo::encode($lat, $lng, 8), now()->addDay(), function () use ($lat, $lng) {
            $res = Http::withHeaders(['Authorization' => 'KakaoAK '.config('services.kakao.rest_key')])
                ->timeout(3)
                ->get(self::REVERSE_URL, ['x' => $lng, 'y' => $lat]);

            if (! $res->successful()) {
                Log::warning('kakao coord2address failed', ['status' => $res->status()]);
                throw new DomainException('PLACE_SEARCH_UNAVAILABLE', '주소를 불러오지 못했어요. 잠시 후 다시 시도해 주세요', 503);
            }

            $doc = $res->json('documents.0');
            if (! $doc) {
                return ['none' => true]; // 바다 위 등: 주소 없음도 캐시
            }
            $road = $doc['road_address'] ?? null;
            $jibun = (string) ($doc['address']['address_name'] ?? '');

            return [
                'address' => (string) ($road['address_name'] ?? '') ?: $jibun,
                'jibun' => $jibun,
                'building_name' => (string) ($road['building_name'] ?? ''),
            ];
        });

        return isset($cached['none']) ? null : $cached;
    }
}
