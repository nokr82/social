<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 주민 광장 참여자.
 * - '참여'(이 테이블)와 '접속'(Redis presence, 30초 heartbeat)을 나눈다:
 *   화면을 떠나도 참여는 유지되고, '나가기'를 눌러야 빠진다 → 채팅 탭 '광장'에 계속 보임
 * - 정원은 참여자 수로 센다. 참여자는 거리와 상관없이 다시 들어올 수 있다
 * - 안 읽은 수 = last_read_id 이후 다른 사람이 보낸 메시지 수
 * - 강퇴·나가기·광장 닫힘 때 행을 지운다
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaza_members', function (Blueprint $table) {
            $table->foreignId('plaza_id')->constrained('plazas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable(); // 마지막으로 광장에 들어온 시각 (나중에 오래 안 온 참여자 정리용)

            $table->primary(['plaza_id', 'user_id']);
            $table->index('user_id'); // 내 광장 목록
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaza_members');
    }
};
