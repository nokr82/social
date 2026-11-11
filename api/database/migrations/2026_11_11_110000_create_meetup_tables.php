<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 번개(오프라인 모임).
 * - 대략 위치(area_label)는 공개, 정확한 장소(place_detail)는 참여자에게만
 * - 나이 조건은 만들 때 실제 범위(age_min~age_max)로 바꿔 저장 → 참여 시 서버가 본인인증 나이로 확인
 * - 채팅방은 chat_rooms(type=meetup, ref_id=meetups.id), 약속 24시간 뒤 닫힘
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plaza_id')->constrained('plazas');
            $table->foreignId('host_id')->constrained('users');
            $table->string('title', 60);
            $table->string('description', 500)->nullable();
            $table->string('area_label', 40);                 // 대략적인 위치 (모두에게)
            $table->string('place_detail', 100);              // 정확한 장소 (참여자만)
            $table->timestamp('starts_at');
            $table->unsignedTinyInteger('capacity');          // 호스트 포함 2~10
            $table->string('age_rule', 8)->default('any');    // any | 20s | 30s | near
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->string('status', 10)->default('open');    // open | canceled
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['plaza_id', 'status', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('meetup_members', function (Blueprint $table) {
            $table->foreignId('meetup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // joined | canceled(1시간 전까지) | late_canceled(1시간 이내, 기록)
            $table->string('status', 16)->default('joined');
            $table->timestamp('arrived_at')->nullable();      // '도착했어요' (자기 신고, 표시용)
            $table->timestamp('noshow_at')->nullable();       // 상호 확인으로 노쇼 확정된 시각
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('canceled_at')->nullable();

            $table->primary(['meetup_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        // 노쇼 상호 확인: 참여자가 다른 참여자를 '안 왔어요'로 체크
        Schema::create('noshow_votes', function (Blueprint $table) {
            $table->foreignId('meetup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['meetup_id', 'voter_id', 'target_id']); // 한 사람에 1표
            $table->index(['meetup_id', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('noshow_votes');
        Schema::dropIfExists('meetup_members');
        Schema::dropIfExists('meetups');
    }
};
