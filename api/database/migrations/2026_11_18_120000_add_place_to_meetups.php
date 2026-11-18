<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 번개 장소를 카카오 장소 검색으로 고를 수 있게.
 * - 고른 장소의 이름·주소·좌표·카카오 장소 id를 저장 (참여 확정자에게만 공개, place_detail과 같은 범위)
 * - 가게·역 같은 공개된 장소의 좌표라 개인 위치가 아님 → '사람 좌표는 저장 안 함' 원칙과 별개
 * - 전부 null 허용: 기존 번개와 '직접 입력' 번개는 place_detail만 있음
 * - place_detail은 null 허용으로 바꿈: 장소를 고르면 메모(출구·자리)는 선택이라서
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetups', function (Blueprint $table) {
            $table->string('place_detail', 100)->nullable()->change();
            $table->string('place_name', 100)->nullable()->after('place_detail');
            $table->string('place_address', 200)->nullable()->after('place_name');
            $table->decimal('place_lat', 10, 7)->nullable()->after('place_address');
            $table->decimal('place_lng', 10, 7)->nullable()->after('place_lat');
            $table->string('place_kakao_id', 20)->nullable()->after('place_lng');
        });
    }

    public function down(): void
    {
        Schema::table('meetups', function (Blueprint $table) {
            $table->dropColumn(['place_name', 'place_address', 'place_lat', 'place_lng', 'place_kakao_id']);
        });
        // 되돌릴 때 메모 없는 번개는 빈 문자열로 채운 뒤 NOT NULL 복구
        DB::table('meetups')->whereNull('place_detail')->update(['place_detail' => '']);
        Schema::table('meetups', function (Blueprint $table) {
            $table->string('place_detail', 100)->nullable(false)->change();
        });
    }
};
