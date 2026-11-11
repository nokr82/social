<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 운영:
 * - admins: 관리자 계정 (앱 사용자와 분리 — 앱은 소셜 로그인, 관리자는 이메일·비밀번호 + 별도 가드)
 * - reports 처리 기록: 누가, 어떤 조치로, 메모
 * - user_sanctions: 정지·해제·영구정지 이력 (현재 상태는 users.status, 이력은 여기)
 * - meetups.settled_at: 번개 종료 후 출석 처리 완료 시각 (중복 처리 방지)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('handled_by')->nullable()->after('status')->constrained('admins')->nullOnDelete();
            $table->string('action', 16)->nullable()->after('handled_by'); // none | warn | suspend | ban
            $table->string('admin_note', 500)->nullable()->after('action');
        });

        Schema::create('user_sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete(); // null = 시스템(자동 정지)
            $table->string('type', 16);                    // suspend | unsuspend | ban
            $table->timestamp('until')->nullable();        // 기간 정지 종료 시각 (null = 무기한)
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_until')->nullable()->after('status'); // 기간 정지 자동 해제용
        });

        Schema::table('meetups', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('canceled_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetups', fn (Blueprint $t) => $t->dropColumn('settled_at'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('suspended_until'));
        Schema::dropIfExists('user_sanctions');
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handled_by');
            $table->dropColumn(['action', 'admin_note']);
        });
        Schema::dropIfExists('admins');
    }
};
