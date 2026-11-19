<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 광장 목록 말풍선(대화 내용 미리보기)을 없애면서, 이미 저장된 미리보기 글도 지운다.
 * 이제 목록엔 '대화 중' 표시만 쓰고(last_message_at), 대화 내용은 광장 참여자만 읽는다.
 * 컬럼은 남겨 둠(되돌리기 쉽게) — 새로 쓰는 곳은 없음
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plazas')->whereNotNull('last_message_body')->update(['last_message_body' => null]);
    }

    public function down(): void
    {
        // 지운 미리보기는 되살리지 않음
    }
};
