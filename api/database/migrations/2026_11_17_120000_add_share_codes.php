<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공유 링크 코드 (/s/p/{code}, /s/m/{code}).
 * 광장·번개 번호(id)를 그대로 쓰면 1,2,3… 바꿔 가며 로그인 없이 전부 훑을 수 있어서 무작위 8글자를 따로 둔다.
 * 처음 '공유하기'를 누를 때 만든다 (기존 행은 비어 있어도 됨).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plazas', function (Blueprint $table) {
            $table->string('share_code', 8)->nullable()->unique();
        });
        Schema::table('meetups', function (Blueprint $table) {
            $table->string('share_code', 8)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('plazas', fn (Blueprint $table) => $table->dropColumn('share_code'));
        Schema::table('meetups', fn (Blueprint $table) => $table->dropColumn('share_code'));
    }
};
