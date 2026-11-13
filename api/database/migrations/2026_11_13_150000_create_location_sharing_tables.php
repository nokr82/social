<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 친구 위치 공유
 * - location_shares: '누가 누구에게 언제까지' 공유하는지 (좌표는 DB에 저장하지 않음 → 캐시에 마지막 1개만)
 * - location_access_logs: 위치정보법 '제공사실 확인자료' — 누가 누구의 위치를 언제 받았는지 (좌표 없음)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sharer_id')->constrained('users')->cascadeOnDelete(); // 위치를 보여주는 사람
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete(); // 보는 친구
            $table->timestamp('expires_at')->nullable();                               // null = 끌 때까지
            $table->timestamps();
            $table->unique(['sharer_id', 'viewer_id']);
            $table->index(['viewer_id', 'expires_at']);
        });

        Schema::create('location_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sharer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_access_logs');
        Schema::dropIfExists('location_shares');
    }
};
