<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\PlazaController;
use App\Http\Controllers\Api\PlazaMessageController;
use App\Http\Controllers\Api\SafetyController;
use Illuminate\Support\Facades\Route;

/*
| 소셜링앱 API (1차: 가입·프로필·광장·채팅·차단신고)
| 모든 경로 앞에 /api 가 붙는다.
*/

// 가입·로그인 (토큰 불필요)
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('social', [AuthController::class, 'social']);      // 1) 소셜 로그인
    Route::post('identity', [AuthController::class, 'identity']);  // 2) PASS 인증 결과
    Route::post('register', [AuthController::class, 'register']);  // 3) 닉네임 → 계정 생성
});

Route::get('meta/categories', [MetaController::class, 'categories']);
Route::get('meta/report-reasons', [MetaController::class, 'reportReasons']);

Route::middleware('auth:sanctum')->group(function () {
    // 정지된 계정도 내 정보 조회·로그아웃은 가능
    Route::get('me', [MeController::class, 'show']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::middleware('active')->group(function () {
        Route::patch('me/profile', [MeController::class, 'updateProfile']);
        Route::put('me/location', [MeController::class, 'updateLocation']);
        Route::put('me/avatar', [MeController::class, 'updateAvatar']);

        Route::get('plazas', [PlazaController::class, 'index']);
        Route::post('plazas', [PlazaController::class, 'store']);
        Route::get('plazas/{plaza}', [PlazaController::class, 'show']);
        Route::post('plazas/{plaza}/enter', [PlazaController::class, 'enter']);
        Route::post('plazas/{plaza}/heartbeat', [PlazaController::class, 'heartbeat']);
        Route::post('plazas/{plaza}/leave', [PlazaController::class, 'leave']);
        Route::get('plazas/{plaza}/messages', [PlazaMessageController::class, 'index']);
        Route::post('plazas/{plaza}/messages', [PlazaMessageController::class, 'store'])->middleware('throttle:chat');

        Route::get('blocks', [SafetyController::class, 'blocks']);
        Route::post('blocks', [SafetyController::class, 'block']);
        Route::delete('blocks/{userId}', [SafetyController::class, 'unblock'])->whereNumber('userId');
        Route::post('reports', [SafetyController::class, 'report']);
    });
});
