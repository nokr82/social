<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) plazas.closed_at · closed_reason: 언제·왜 닫혔나 (대화 보관 기간 계산, 관리자 확인용)
 * 2) plaza_kicks: 방장 강퇴 기록. expires_at까지 그 광장 재입장 불가
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plazas', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('host_seen_at');
            $table->string('closed_reason', 16)->nullable()->after('closed_at'); // host_left | host_closed | admin
            $table->index(['status', 'closed_at']); // 오래된 대화 정리용
        });
        // 이미 닫힌 광장: 마지막 갱신 시각을 닫힌 시각으로 간주
        DB::table('plazas')->where('status', 'closed')->whereNull('closed_at')
            ->update(['closed_at' => DB::raw('updated_at'), 'closed_reason' => 'host_left']);

        Schema::create('plaza_kicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plaza_id')->constrained('plazas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();   // 강퇴당한 사람
            $table->foreignId('kicked_by')->constrained('users')->cascadeOnDelete(); // 강퇴한 방장
            $table->string('reason', 16);                                            // config social.plaza.kick_reasons
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['plaza_id', 'user_id', 'expires_at']); // 입장 검사
            $table->index(['user_id', 'expires_at']);             // 목록에서 내가 강퇴된 광장 숨김
            $table->index(['plaza_id', 'created_at']);            // 하루 강퇴 한도
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaza_kicks');
        Schema::table('plazas', function (Blueprint $table) {
            $table->dropIndex(['status', 'closed_at']);
            $table->dropColumn(['closed_at', 'closed_reason']);
        });
    }
};
