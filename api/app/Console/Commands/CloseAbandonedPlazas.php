<?php

namespace App\Console\Commands;

use App\Models\Plaza;
use App\Services\Plaza\PlazaService;
use Illuminate\Console\Command;

/**
 * 방장이 떠난 광장 처리 (스케줄러가 1분마다 실행).
 * 방장이 나간 뒤 social.plaza.host_grace 초 안에 돌아오지 않은 열린 광장이 대상.
 * - 자격 있는 접속자가 있으면 → 방장 위임
 * - 없으면 → 닫고 남은 접속자에게 알림
 */
class CloseAbandonedPlazas extends Command
{
    protected $signature = 'plazas:close-abandoned';

    protected $description = '방장이 떠난 광장을 다른 사람에게 넘기거나 닫습니다';

    public function handle(PlazaService $plazas): int
    {
        $counts = ['transferred' => 0, 'closed' => 0, 'kept' => 0];

        Plaza::hostGone()->orderBy('id')->chunkById(200, function ($chunk) use ($plazas, &$counts) {
            foreach ($chunk as $plaza) {
                $counts[$plazas->handleHostGone($plaza)]++;
            }
        });

        $this->info("방장 위임: {$counts['transferred']}개, 닫음: {$counts['closed']}개");

        return self::SUCCESS;
    }
}
