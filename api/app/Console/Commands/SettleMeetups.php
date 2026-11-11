<?php

namespace App\Console\Commands;

use App\Models\Meetup;
use App\Models\MeetupMember;
use App\Models\TrustScore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 번개 출석 정산 (매시간 스케줄러가 실행).
 * 채팅방이 닫힌(약속 24시간 경과) 번개만 대상: 노쇼 투표가 끝난 뒤라 결과가 확정돼 있음.
 * - 참여 확정 + 노쇼 아님 → attend_count +1, 매너점수 +attend_bonus (최대 1000)
 * - settled_at을 찍어 두 번 정산되지 않게 (명령이 겹쳐 실행돼도 안전하도록 조건부 UPDATE로 선점)
 */
class SettleMeetups extends Command
{
    protected $signature = 'meetups:settle';

    protected $description = '끝난 번개의 출석을 정산해 매너점수에 반영합니다';

    public function handle(): int
    {
        $bonus = (int) config('social.trust.attend_bonus');
        $settled = 0;

        Meetup::where('status', Meetup::OPEN)
            ->whereNull('settled_at')
            ->where('starts_at', '<=', now()->subHours(Meetup::ROOM_HOURS))
            ->orderBy('id')
            ->chunkById(100, function ($meetups) use ($bonus, &$settled) {
                foreach ($meetups as $meetup) {
                    DB::transaction(function () use ($meetup, $bonus, &$settled) {
                        // 선점: 다른 프로세스가 먼저 정산했으면 0행 → 건너뜀
                        $claimed = Meetup::whereKey($meetup->id)->whereNull('settled_at')->update(['settled_at' => now()]);
                        if (! $claimed) {
                            return;
                        }
                        $attended = MeetupMember::where('meetup_id', $meetup->id)
                            ->where('status', MeetupMember::JOINED)
                            ->whereNull('noshow_at')
                            ->pluck('user_id');

                        if ($attended->isNotEmpty()) {
                            TrustScore::whereIn('user_id', $attended)->update([
                                'attend_count' => DB::raw('attend_count + 1'),
                                'score' => DB::raw("CASE WHEN score + {$bonus} > 1000 THEN 1000 ELSE score + {$bonus} END"), // SQLite(테스트)·MariaDB 공통 문법
                                'updated_at' => now(),
                            ]);
                        }
                        $settled++;
                    });
                }
            });

        $this->info("정산한 번개: {$settled}개");

        return self::SUCCESS;
    }
}
