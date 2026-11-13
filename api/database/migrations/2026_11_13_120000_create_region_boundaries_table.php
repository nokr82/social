<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 동네(시·군·구) 경계. 외부 API 없이 "이 좌표가 어느 구 안인가"를 서버가 직접 판단하기 위한 표.
 * - 원본: 통계청 SGIS 행정동 경계를 가공한 vuski/admdongkor (php artisan regions:import 로 적재)
 * - 행 = 행정동 1개(약 3,600행). 행정동 경계를 그대로 두고 code에 소속 시·군·구를 적어
 *   "행정동 안에 있으면 그 동이 속한 구" 로 판단 → 구 경계를 따로 합칠(dissolve) 필요가 없음
 * - min/max 위경도(외곽 사각형)로 후보를 먼저 좁히고, 정밀 판정(다각형 안/밖)은 PHP에서
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_boundaries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16);       // 'SGG-11680' (시군구 코드 5자리, 카카오 법정동 코드와 섞이지 않게 접두어)
            $table->string('name', 40);       // 화면 이름: '강남구', '부산 중구', '고양시 덕양구'
            $table->string('dong_code', 10);  // 원본 행정동 코드 (확인·디버깅용)
            $table->double('min_lat');
            $table->double('max_lat');
            $table->double('min_lng');
            $table->double('max_lng');
            $table->longText('rings');        // JSON: [다각형[고리[lng,lat,lng,lat,...]]] (소수 5자리 ≈ 1m)
            $table->index(['min_lat', 'max_lat']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_boundaries');
    }
};
