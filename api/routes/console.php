<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 번개 출석 정산: 매시간 (docker compose의 scheduler 서비스가 schedule:work로 실행)
Schedule::command('meetups:settle')->hourly()->withoutOverlapping();

// 번개 1시간 전 알림: 5분마다
Schedule::command('meetups:remind')->everyFiveMinutes()->withoutOverlapping();
