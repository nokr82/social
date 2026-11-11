<?php

namespace App\Console\Commands;

use App\Models\Meetup;
use App\Notifications\Notify;
use App\Services\Meetup\MeetupService;
use Illuminate\Console\Command;

/**
 * 번개 1시간 전 알림 (스케줄러가 5분마다 실행).
 * - 시작까지 1시간 이하로 남았고 아직 안 보낸 번개 → 참여자 전원에게
 * - 만든 지 얼마 안 된 번개(시작 1시간 이내에 만든 것)는 다들 방금 확인했으니 보내지 않음
 * - reminded_at을 조건부 UPDATE로 먼저 찍어 중복 발송 방지
 */
class RemindMeetups extends Command
{
    protected $signature = 'meetups:remind';

    protected $description = '1시간 뒤 시작하는 번개의 참여자에게 알림을 보냅니다';

    public function handle(MeetupService $meetups): int
    {
        $sent = 0;

        Meetup::where('status', Meetup::OPEN)
            ->whereNull('reminded_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHour())
            ->orderBy('id')
            ->each(function (Meetup $meetup) use ($meetups, &$sent) {
                $claimed = Meetup::whereKey($meetup->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);
                if (! $claimed) {
                    return;
                }
                if ($meetup->created_at->gt($meetup->starts_at->copy()->subHour())) {
                    return; // 표시만 하고 발송 생략
                }
                Notify::meetupReminder($meetup, $meetups->joinedUsers($meetup));
                $sent++;
            });

        $this->info("알림 보낸 번개: {$sent}개");

        return self::SUCCESS;
    }
}
