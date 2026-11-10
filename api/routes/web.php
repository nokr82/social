<?php

use Illuminate\Support\Facades\Route;

// 소셜링 앱(Vue SPA): API·웹소켓·헬스체크를 뺀 모든 경로를 앱에 넘긴다 (화면 라우팅은 vue-router)
Route::view('/{any?}', 'app')->where('any', '^(?!api|broadcasting|up|build|storage).*$');
