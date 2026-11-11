<?php

namespace App\Services\Region;

use App\Exceptions\DomainException;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 카카오 로컬 API 좌표→행정구역(coord2regioncode)으로 법정동을 찾는다.
 * - 행정동(H, '역삼1동')이 아니라 법정동(B, '역삼동')을 쓴다: 사람들이 부르는 동네 이름과 같고 범위도 넓어 광장이 덜 쪼개짐
 * - 결과는 geohash 7자리 칸(약 150m)마다 30일 캐시 → 같은 동네 사용자는 API를 거의 호출하지 않음
 *   (동 경계는 거의 바뀌지 않으므로 오래 캐시해도 안전)
 * - API 키: 카카오 로그인과 같은 앱의 'REST API 키' (KAKAO_REST_API_KEY)
 */
class KakaoRegionResolver implements RegionResolver
{
    private const URL = 'https://dapi.kakao.com/v2/local/geo/coord2regioncode.json';

    public function resolve(float $lat, float $lng): ?Region
    {
        $cell = Geo::encode($lat, $lng, 7);

        $cached = Cache::remember("region:{$cell}", now()->addDays(30), function () use ($lat, $lng) {
            $res = Http::withHeaders(['Authorization' => 'KakaoAK '.config('services.kakao.rest_key')])
                ->timeout(3)
                ->get(self::URL, ['x' => $lng, 'y' => $lat]); // 카카오는 x=경도, y=위도

            if (! $res->successful()) {
                // 키 오류·한도 초과 등: 캐시하지 않도록 예외로 빠져나감
                Log::warning('kakao coord2regioncode failed', ['status' => $res->status()]);
                throw new DomainException('REGION_UNAVAILABLE', '동네 정보를 불러오지 못했어요. 잠시 후 다시 시도해 주세요', 503);
            }

            $doc = collect($res->json('documents', []))->firstWhere('region_type', 'B');
            if (! $doc) {
                return ['none' => true]; // 바다 위 등 동네 없음도 캐시 (반복 호출 방지)
            }

            return [
                'code' => (string) $doc['code'],
                // 읍·면 지역은 3단계가 '○○면', 리 단위는 4단계 → 3단계 우선
                'name' => $doc['region_3depth_name'] ?: ($doc['region_2depth_name'] ?? ''),
            ];
        });

        return isset($cached['code']) ? new Region($cached['code'], $cached['name']) : null;
    }
}
