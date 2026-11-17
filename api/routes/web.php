<?php

use App\Http\Controllers\SharePreviewController;
use Illuminate\Support\Facades\Route;

// 공유 링크 미리보기 (로그인 없이, 서버가 og 태그를 그린 HTML). 아래 '앱으로 넘기기'보다 먼저 등록해야 잡힌다.
// 코드 형식이 아니면 바로 404, 분당 60번 제한(코드 무작위 대입 방지)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/s/p/{code}', [SharePreviewController::class, 'plaza'])->where('code', '[a-z0-9]{8}');
    Route::get('/s/m/{code}', [SharePreviewController::class, 'meetup'])->where('code', '[a-z0-9]{8}');
});

// 소셜링 앱(Vue SPA): API·웹소켓·헬스체크·관리자(Filament)를 뺀 모든 경로를 앱에 넘긴다 (화면 라우팅은 vue-router)
Route::view('/{any?}', 'app')->where('any', '^(?!api|broadcasting|up|build|storage|admin|livewire|filament|css|js|fonts).*$');
