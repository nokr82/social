<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 광장과 채팅.
 * - 광장 접속자·아바타 좌표는 DB에 두지 않는다 (Redis / Reverb)
 * - chat_rooms.type 하나로 광장·모임·DM·소개 대화를 모두 처리
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plazas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users');
            $table->string('name', 40);
            $table->string('description', 200)->nullable();
            $table->string('category', 16);            // talk | sports | food | game ... (config/social.php)
            // geohash 7자리(약 150m 칸). lat/lng는 이 칸의 '중심값'이라 실제 위치를 노출하지 않음
            $table->char('region_cell', 7);
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->unsignedSmallInteger('capacity')->default(30);
            $table->string('status', 16)->default('open'); // open | closed
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'lat', 'lng']);   // 주변 검색(바운딩 박스)
            $table->index(['status', 'category']);
            $table->index('region_cell');
        });

        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);                // plaza | meetup | dm | match
            $table->unsignedBigInteger('ref_id');      // type에 따라 plazas.id 등
            $table->timestamps();

            $table->unique(['type', 'ref_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('chat_rooms')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->string('type', 16)->default('text'); // text | system
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            // 방별 최신순 페이지네이션 (WHERE room_id=? AND id<? ORDER BY id DESC)
            $table->index(['room_id', 'id']);
        });

        // 차단: 한 방향으로 저장하고, 조회할 때 양방향으로 적용
        Schema::create('blocks', function (Blueprint $table) {
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['blocker_id', 'blocked_id']);
            $table->index('blocked_id');
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users');
            $table->foreignId('target_id')->constrained('users');   // 신고 대상 사용자
            $table->string('target_type', 16);         // user | message | plaza
            $table->unsignedBigInteger('target_ref_id')->nullable(); // 메시지·광장 ID
            $table->string('reason', 32);              // abuse | sexual | spam | fake ...
            $table->string('detail', 500)->nullable();
            $table->string('status', 16)->default('pending'); // pending | accepted | rejected
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['target_id', 'status']);
            // 같은 사람이 같은 대상을 중복 신고해 누적 횟수를 부풀리는 것 방지
            $table->unique(['reporter_id', 'target_id', 'target_type', 'target_ref_id'], 'reports_dedupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_rooms');
        Schema::dropIfExists('plazas');
    }
};
