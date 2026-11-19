<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 번개 '모이는 중' 위치 공유: 이 번개의 다른 참여 확정자에게 내 위치를 보여주기로 한 사람.
 * - 행이 있으면 켜짐 (기간 없음 — 사용자가 끄거나, 참여 취소·번개 취소·채팅방 닫힘 때 끝)
 * - 좌표는 여기에 없음: 친구 위치 공유와 같은 캐시(loc:last:{user})의 마지막 1개만 씀
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetup_location_shares', function (Blueprint $table) {
            $table->foreignId('meetup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['meetup_id', 'user_id']);
            $table->index('user_id'); // 위치 갱신 때 '내가 켠 번개' 조회
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetup_location_shares');
    }
};
