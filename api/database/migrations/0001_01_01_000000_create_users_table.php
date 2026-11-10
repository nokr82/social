<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 계정 테이블.
 * - 이메일·비밀번호 없음: 로그인은 소셜(social_accounts) + Sanctum 토큰
 * - 성별·생년월일 없음: user_identities에만 둔다 (프로필 수정으로 조작 방지)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nickname', 20)->unique();
            // active: 정상 / suspended: 신고 누적 임시정지 / banned: 영구정지 / withdrawn: 탈퇴
            $table->string('status', 16)->default('active')->index();
            // geohash 6자리(약 1.2km x 0.6km 칸). 정확한 GPS는 저장하지 않음
            $table->char('region_cell', 6)->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 세션 테이블은 Laravel 기본값 유지 (SESSION_DRIVER=redis면 안 쓰임)
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
