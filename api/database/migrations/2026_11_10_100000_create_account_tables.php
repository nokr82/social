<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 계정 부속 테이블: 소셜 로그인, 본인인증, 프로필, 아바타, 매너점수
 * 모두 users와 1:1(social_accounts만 1:N)이며, 사용자 삭제 시 함께 삭제된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 소셜 로그인 연결. 한 사용자가 카카오·애플을 둘 다 연결할 수 있음
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 10);            // kakao | apple
            $table->string('provider_user_id', 64);    // 각 플랫폼의 고유 사용자 ID
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        // PASS 본인인증 결과. 수정 API를 만들지 않는다 (updated_at 없음)
        Schema::create('user_identities', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('ci_hash', 64)->unique();     // HMAC-SHA256(CI). 원문 CI는 저장 안 함
            $table->char('gender', 1);                 // M | F
            $table->date('birth_date');
            $table->string('provider', 16);            // pass 등 인증 대행사
            $table->timestamp('verified_at');
        });

        // 영구정지 명단. 탈퇴로 user_identities가 지워져도 재가입을 막기 위해 분리
        Schema::create('banned_identities', function (Blueprint $table) {
            $table->char('ci_hash', 64)->primary();
            $table->string('reason', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('bio', 300)->nullable();
            $table->json('interests')->nullable();     // ["러닝","보드게임"]
            $table->timestamps();
        });

        // 캐릭터 파츠 조합. 파츠 이미지는 오브젝트 스토리지, 여기엔 파츠 ID만
        Schema::create('avatars', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('parts');                     // {"face":"f03","color":"peach",...}
            $table->unsignedInteger('version')->default(1); // 변경 시 +1 → 앱 캐시 무효화
            $table->timestamps();
        });

        // 매너점수. 0~1000, 기본 500. 계산은 큐 워커, 산식 비공개
        Schema::create('trust_scores', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(500);
            $table->unsignedInteger('attend_count')->default(0);
            $table->unsignedInteger('noshow_count')->default(0);
            $table->unsignedInteger('report_count')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_scores');
        Schema::dropIfExists('avatars');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('banned_identities');
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('social_accounts');
    }
};
