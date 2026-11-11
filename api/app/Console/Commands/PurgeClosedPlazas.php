<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Plaza;
use App\Models\PlazaKick;
use App\Models\Report;
use Illuminate\Console\Command;

/**
 * 닫힌 광장 정리 (매일 새벽).
 * - 닫힌 지 closed_message_days(30일) 지난 광장의 대화 삭제 → 저장공간·개인정보 부담 감소
 *   단, 신고된 메시지는 관리자 판단 근거라 남긴다
 * - 강퇴 기록 삭제: 해제한 지 7일 지난 것 + 닫힌 지 30일 지난 광장의 것 (더는 입장 검사·한도 계산에 안 쓰임)
 * 광장 자체(이름 등)는 신고·친구 '만난 광장' 표시에 쓰이므로 남긴다.
 */
class PurgeClosedPlazas extends Command
{
    protected $signature = 'plazas:purge';

    protected $description = '닫힌 지 오래된 광장의 대화와 필요 없어진 강퇴 기록을 지웁니다';

    public function handle(): int
    {
        $before = now()->subDays((int) config('social.plaza.closed_message_days'));
        $deleted = 0;

        // 신고된 메시지 id (증거 보존)
        $reported = Report::where('target_type', 'message')->whereNotNull('target_ref_id')->pluck('target_ref_id');

        Plaza::where('status', Plaza::STATUS_CLOSED)->where('closed_at', '<', $before)
            ->select('id')->chunkById(200, function ($plazas) use ($reported, &$deleted) {
                $roomIds = ChatRoom::where('type', ChatRoom::TYPE_PLAZA)->whereIn('ref_id', $plazas->pluck('id'))->pluck('id');
                if ($roomIds->isEmpty()) {
                    return;
                }
                // 한 번에 너무 많이 지우면 테이블 잠금이 길어지므로 1000개씩
                do {
                    $ids = ChatMessage::whereIn('room_id', $roomIds)->whereNotIn('id', $reported)->limit(1000)->pluck('id');
                    $deleted += ChatMessage::whereIn('id', $ids)->delete();
                } while ($ids->count() === 1000);
            });

        $kicks = PlazaKick::where('revoked_at', '<', now()->subDays(7))->delete()
            + PlazaKick::whereIn('plaza_id', Plaza::where('status', Plaza::STATUS_CLOSED)->where('closed_at', '<', $before)->select('id'))->delete();

        $this->info("삭제한 대화: {$deleted}개, 강퇴 기록: {$kicks}개");

        return self::SUCCESS;
    }
}
