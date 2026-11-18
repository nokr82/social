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

            return collect($res->json('documents', []))->map(fn (array $d) => [
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
}
