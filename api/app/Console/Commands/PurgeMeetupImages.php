<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Meetup;
use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 번개방 사진 정리 (매일 새벽).
 * 채팅방이 닫힌(약속 24시간 뒤) 지 30일 지난 번개의 사진 파일을 지운다.
 * - 메시지 행은 남기고 meta를 {expired:true}로 바꿈 → 화면에 '보관 기간이 지난 사진'
 * - 신고된 사진은 관리자 판단 근거라 남긴다
 * DM 사진은 친구끼리의 기록이라 지우지 않는다 (탈퇴 시 정리는 별도).
 */
class PurgeMeetupImages extends Command
{
    public const KEEP_DAYS = 30;

    protected $signature = 'meetups:purge-images';

    protected $description = '닫힌 지 30일 지난 번개방의 사진 파일을 지웁니다';

    public function handle(): int
    {
        $before = now()->subHours(Meetup::ROOM_HOURS)->subDays(self::KEEP_DAYS);
        $reported = Report::where('target_type', 'message')->whereNotNull('target_ref_id')->pluck('target_ref_id');
        $disk = Storage::disk(config('social.photos.disk'));
        $deleted = 0;

        Meetup::where('starts_at', '<', $before)->select('id')->chunkById(200, function ($meetups) use ($reported, $disk, &$deleted) {
            $roomIds = ChatRoom::where('type', ChatRoom::TYPE_MEETUP)->whereIn('ref_id', $meetups->pluck('id'))->pluck('id');

            ChatMessage::whereIn('room_id', $roomIds)
                ->where('type', ChatMessage::IMAGE)
                ->whereNotIn('id', $reported)
                ->whereNotNull('meta->path')   // 아직 파일이 있는 것만 (이미 지운 건 건너뜀)
                ->chunkById(500, function ($messages) use ($disk, &$deleted) {
                    $disk->delete($messages->pluck('meta.path')->filter()->all());
                    foreach ($messages as $m) {
                        $m->update(['meta' => ['expired' => true]]);
                    }
                    $deleted += $messages->count();
                });
        });

        $this->info("지운 사진: {$deleted}장");

        return self::SUCCESS;
    }
}
