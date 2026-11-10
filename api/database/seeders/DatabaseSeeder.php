<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Plaza\PlazaService;
use Illuminate\Database\Seeder;

/**
 * 개발용 데이터: 디자인 시안의 역삼역 주변 광장들.
 * 실행: docker compose exec php php artisan migrate:fresh --seed
 */
class DatabaseSeeder extends Seeder
{
    public function run(PlazaService $plazas): void
    {
        $hosts = User::factory()->count(5)->complete()->create();

        // [이름, 카테고리, 위도, 경도] — 역삼역(37.5006, 127.0364) 기준
        $seed = [
            ['강남역 롤 듀오 구해요', 'game', 37.4979, 127.0276],
            ['역삼 퇴근길 수다방', 'talk', 37.5010, 127.0380],
            ['선릉 점심 맛집 탐방', 'food', 37.5045, 127.0490],
            ['테헤란로 러닝크루', 'sports', 37.5030, 127.0420],
            ['강남 스터디 카페 모임', 'study', 37.4990, 127.0300],
        ];

        foreach ($seed as $i => [$name, $category, $lat, $lng]) {
            $plazas->create($hosts[$i], ['name' => $name, 'category' => $category], $lat, $lng);
        }
    }
}
