<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1:1 대화(DM) 참여자와 읽음 위치.
 * - 안 읽은 수 = 내 last_read_id 이후 상대가 보낸 메시지 수
 * - 상대의 last_read_id ≥ 내 메시지 id 이면 '읽음'
 * (광장 채팅은 접속자가 Redis에만 있으므로 이 테이블을 쓰지 않음)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_room_members', function (Blueprint $table) {
            $table->foreignId('room_id')->constrained('chat_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['room_id', 'user_id']);
            $table->index('user_id'); // 내 대화 목록
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_room_members');
    }
};
