<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 메인 타일의 말풍선(광장에서 방금 한 말)용 미리보기.
 * 목록마다 chat_messages를 조회하면 N+1이 되므로 광장에 최신 1줄을 복사해 둔다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plazas', function (Blueprint $table) {
            $table->string('last_message_body', 60)->nullable()->after('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('plazas', function (Blueprint $table) {
            $table->dropColumn('last_message_body');
        });
    }
};
