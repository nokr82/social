<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 번개 친구 초대 기록.
 * - UNIQUE(meetup_id, invitee_id): 같은 친구는 번개 하나당 1번만 초대받음 (여러 참여자가 같은 친구를 불러도 카드 1장)
 * - INDEX(meetup_id, inviter_id): 한 사람이 번개 하나에서 보낸 초대 수 제한(20명) 확인용
 * 초대는 입장권이 아님 → 참여할 때 연령·정원·차단 검사는 평소대로
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetup_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meetup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['meetup_id', 'invitee_id']);
            $table->index(['meetup_id', 'inviter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetup_invites');
    }
};
