<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 가입 도중 저장소: 본인인증까지 마치고 닉네임 설정 전에 나간 사람의 인증 결과를 보관.
 * 같은 소셜 계정으로 다시 들어오면 인증을 건너뛴다 (7일 후 자동 삭제).
 * 이전에는 15분짜리 캐시 토큰에만 있어서, 새로고침·앱 종료만 해도 인증을 다시 해야 했다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_signups', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->text('identity');             // 암호화된 JSON: ci_hash, gender, birth_date, provider
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']); // 소셜 계정당 1건
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_signups');
    }
};
