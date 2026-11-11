<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 채팅 첨부: type에 image·meetup 추가 (컬럼은 그대로 string)
 * - image  : meta {path, w, h}   body = '사진' (목록 미리보기·푸시 문구용)
 * - meetup : meta {meetup_id}    body = 번개 제목
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', fn (Blueprint $table) => $table->dropColumn('meta'));
    }
};
