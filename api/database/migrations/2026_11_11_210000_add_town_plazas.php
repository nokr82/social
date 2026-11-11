<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상시 광장(동네 광장).
 * - plazas.type: user(주민이 만든 광장) | town(법정동마다 1개, 항상 열려 있음)
 * - plazas.region_code: 상시 광장의 법정동 코드 (동네당 1개 보장)
 * - plazas.host_id: 상시 광장은 방장이 없으므로 nullable
 * - chat_messages.channel: 상시 광장은 같은 '구역:채널'끼리만 대화 → 그 단위로 조회
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plazas', function (Blueprint $table) {
            $table->string('type', 8)->default('user')->after('id');
            $table->string('region_code', 16)->nullable()->unique()->after('region_cell');
            $table->foreignId('host_id')->nullable()->change();
            $table->index(['type', 'status']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('channel', 24)->nullable()->after('room_id'); // 상시 광장: "fountain:1"
            $table->index(['room_id', 'channel', 'id']);               // 구역·채널별 최신순 페이지네이션
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'channel', 'id']);
            $table->dropColumn('channel');
        });
        Schema::table('plazas', function (Blueprint $table) {
            $table->dropIndex(['type', 'status']);
            $table->dropUnique(['region_code']);
            $table->dropColumn(['type', 'region_code']);
            // host_id는 상시 광장 행이 남아 있으면 NOT NULL로 되돌릴 수 없어 그대로 둔다
        });
    }
};
