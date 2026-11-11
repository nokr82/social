<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 방장이 마지막으로 광장에 있던 시각.
 * 방장이 나간 뒤 social.plaza.host_grace 초 안에 돌아오지 않으면 광장을 닫는다.
 * (접속 여부는 Redis에 있지만, '언제부터 없었나'는 Redis가 비워져도 남아야 해서 DB에 둔다)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plazas', function (Blueprint $table) {
            $table->timestamp('host_seen_at')->nullable()->after('last_message_body');
            $table->index(['status', 'host_seen_at']); // 버려진 광장 정리 명령용
        });

        // 기존 광장: 마지막 갱신 시각으로 채움 → 방장이 없는 오래된 광장은 다음 정리 때 닫힌다
        DB::table('plazas')->whereNull('host_seen_at')->update(['host_seen_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('plazas', function (Blueprint $table) {
            $table->dropIndex(['status', 'host_seen_at']);
            $table->dropColumn('host_seen_at');
        });
    }
};
