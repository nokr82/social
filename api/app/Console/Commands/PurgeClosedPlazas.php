<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Plaza;
use App\Models\PlazaKick;
use App\Models\PlazaMember;
use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 닫힌 광장 정리 (매일 새벽).
 * - 닫힌 지 closed_message_days(30일) 지난 광장의 대화 삭제 → 저장공간·개인정보 부담 감소
 *   단, 신고된 메시지는 관리자 판단 근거라 남긴다
 * - 강퇴 기록 삭제: 해제한 지 7일 지난 것 + 닫힌 지 30일 지난 광장의 것 (더는 입장 검사·한도 계산에 안 쓰임)
 * - 오래 안 오는 참여자 정리: member_idle_days(14일) 동안 한 번도 안 들어온 사람은 참여에서 뺌
 *   (정원이 참여자 수라 자리를 계속 차지하지 않게). 지금 방장은 빼지 않음 — 방장 부재는 host_grace(72시간)가 처리
 * 광장 자체(이름 등)는 신고·친구 '만난 광장' 표시에 쓰이므로 남긴다.
 */
class PurgeClosedPlazas extends Command
{
    protected $signature = 'plazas:purge';

    protected $description = '닫힌 지 오래된 광장의 대화, 필요 없어진 강퇴 기록, 오래 안 온 참여자를 정리합니다';

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

        $members = $this->pruneIdleMembers();

        $this->info("삭제한 대화: {$deleted}개, 강퇴 기록: {$kicks}개, 정리한 참여자: {$members}명");

        return self::SUCCESS;
    }

    /**
     * 마지막 입장(last_seen_at, 없으면 참여 시각)이 기준보다 오래된 참여자 삭제. 지금 방장인 행은 제외.
     * 채팅 탭 목록에서 조용히 빠지고, 다시 들어오려면 처음 참여처럼 5km·정원 검사를 받는다.
     */
    private function pruneIdleMembers(): int
    {
        $before = now()->subDays((int) config('social.plaza.member_idle_days'));

        return PlazaMember::query()
            ->where(fn ($q) => $q->where('last_seen_at', '<', $before)
                ->orWhere(fn ($q) => $q->whereNull('last_seen_at')->where('joined_at', '<', $before)))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('plazas')
                ->whereColumn('plazas.id', 'plaza_members.plaza_id')
                ->whereColumn('plazas.host_id', 'plaza_members.user_id'))
            ->delete();
    }
}
