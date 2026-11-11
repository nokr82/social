<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 묶음
 * - notifications: Laravel 기본 알림 저장소 (알림센터 목록·읽음)
 * - notification_settings: 푸시 설정 (행이 없으면 기본값 = 전부 켬, 야간 무음 끔)
 * - meetups.reminded_at: '1시간 전' 알림을 한 번만 보내기 위한 표시
 * 푸시 구독(push_subscriptions)은 webpush 패키지가 제공하는 마이그레이션을 쓴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']); // 안 읽은 수 집계
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('friend')->default(true);      // 친구 요청·수락
            $table->boolean('chat')->default(true);        // DM·번개방 메시지
            $table->boolean('meetup')->default(true);      // 번개 참여·취소·1시간 전
            $table->boolean('night_quiet')->default(false); // 22~08시 푸시 끔
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('meetups', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetups', fn (Blueprint $table) => $table->dropColumn('reminded_at'));
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
    }
};
