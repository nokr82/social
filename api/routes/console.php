<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 번개 출석 정산: 매시간 (docker compose의 scheduler 서비스가 schedule:work로 실행)
Schedule::command('meetups:settle')->hourly()->withoutOverlapping();

// 방장이 떠난 광장: 자격 있는 접속자에게 위임, 없으면 닫기. 1분마다 (유예시간은 social.plaza.host_grace)
Schedule::command('plazas:close-abandoned')->everyMinute()->withoutOverlapping();

// 닫힌 지 30일 지난 광장 대화 삭제(신고된 메시지 제외) + 끝난 강퇴 기록 정리: 매일 새벽 4시(한국 시간, 앱 시간대는 UTC)
Schedule::command('plazas:purge')->dailyAt('04:00')->timezone('Asia/Seoul')->withoutOverlapping();

// 번개 1시간 전 알림: 5분마다
Schedule::command('meetups:remind')->everyFiveMinutes()->withoutOverlapping();

// 닫힌 지 30일 지난 번개방 사진 파일 삭제: 매일 새벽 4시 30분(한국 시간)
Schedule::command('meetups:purge-images')->dailyAt('04:30')->timezone('Asia/Seoul')->withoutOverlapping();

// DB 백업 → 버킷(backups/db), 14일 보관: 매일 새벽 3시 30분(한국 시간)
Schedule::command('social:backup-db')->dailyAt('03:30')->timezone('Asia/Seoul')->withoutOverlapping()->environments(['production']);

// 만료된 가입 도중 보관분(pending_signups, 7일) 삭제: 매일 새벽 4시(한국 시간)
Schedule::command('model:prune', ['--model' => [\App\Models\PendingSignup::class]])->dailyAt('04:00')->timezone('Asia/Seoul');

// 위치 제공 확인자료(location_access_logs) 1년 지난 것 삭제: 매일 새벽 4시 10분(한국 시간)
Schedule::command('model:prune', ['--model' => [\App\Models\LocationAccessLog::class]])->dailyAt('04:10')->timezone('Asia/Seoul');
