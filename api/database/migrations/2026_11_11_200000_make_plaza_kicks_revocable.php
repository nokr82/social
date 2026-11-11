<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 강퇴를 '24시간 기한'에서 '방장이 해제할 때까지'로 변경.
 * 실수로 내보낸 경우 방장이 강퇴 목록에서 해제할 수 있다.
 * - expires_at: 더는 쓰지 않음 (nullable로만 바꿔 기존 기록 보존)
 * - revoked_at · revoked_by: 해제 시각·해제한 방장 (기록은 남겨 하루 강퇴 한도·분쟁 확인에 사용)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plaza_kicks', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();

            $table->index(['plaza_id', 'user_id', 'revoked_at']); // 입장 검사·강퇴 목록
            $table->index(['user_id', 'revoked_at']);             // 목록에서 내가 강퇴된 광장 숨김
        });
    }

    public function down(): void
    {
        Schema::table('plaza_kicks', function (Blueprint $table) {
            $table->dropIndex(['plaza_id', 'user_id', 'revoked_at']);
            $table->dropIndex(['user_id', 'revoked_at']);
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn('revoked_at');
        });
    }
};
