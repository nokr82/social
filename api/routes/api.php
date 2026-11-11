<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MeetupController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PlazaController;
use App\Http\Controllers\Api\PlazaMessageController;
use App\Http\Controllers\Api\FriendRequestController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\SafetyController;
use App\Http\Controllers\Api\UserProfileController;
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

// 사진 파일 (로컬 저장소용): 토큰 대신 서명으로 접근 → <img src>로 바로 사용
Route::get('photos/{photo}/file', [PhotoController::class, 'file'])
    ->middleware('signed:relative')->name('photos.file');
Route::get('meta/report-reasons', [MetaController::class, 'reportReasons']);
Route::get('meta/push', [MetaController::class, 'push']);

Route::middleware(['auth:sanctum', 'seen'])->group(function () {
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

        // 내 사진 (MY)
        Route::get('me/photos', [PhotoController::class, 'index']);
        Route::post('me/photos', [PhotoController::class, 'store'])->middleware('throttle:20,1');
        Route::patch('me/photos/{photo}', [PhotoController::class, 'update']);
        Route::delete('me/photos/{photo}', [PhotoController::class, 'destroy']);
        Route::post('photos/{photo}/like', [PhotoController::class, 'like'])->middleware('throttle:60,1');

        // 상대 프로필
        Route::get('users/{user}', [UserProfileController::class, 'show']);

        // 친구 요청
        Route::get('friend-requests', [FriendRequestController::class, 'index']);
        Route::post('friend-requests', [FriendRequestController::class, 'store']);
        Route::post('friend-requests/{friendRequest}/accept', [FriendRequestController::class, 'accept']);
        Route::post('friend-requests/{friendRequest}/reject', [FriendRequestController::class, 'reject']);
        Route::post('friend-requests/{friendRequest}/undo', [FriendRequestController::class, 'undo']);
        Route::delete('friend-requests/{friendRequest}', [FriendRequestController::class, 'destroy']);

        // 채팅 탭 · 1:1 대화 (친구끼리만)
        Route::get('chats', [ChatController::class, 'index']);
        Route::get('friends', [ChatController::class, 'friends']);
        Route::post('dm/{user}', [ChatController::class, 'open']);
        Route::get('chats/{room}', [ChatController::class, 'show']);
        Route::get('chats/{room}/messages', [ChatController::class, 'messages']);
        Route::post('chats/{room}/messages', [ChatController::class, 'send'])->middleware('throttle:chat');
        Route::post('chats/{room}/read', [ChatController::class, 'read']);

        // 번개
        Route::get('meetups', [MeetupController::class, 'index']);
        Route::get('meetups/mine', [MeetupController::class, 'mine']);
        Route::get('plazas/{plaza}/meetups', [MeetupController::class, 'forPlaza']);
        Route::post('plazas/{plaza}/meetups', [MeetupController::class, 'store']);
        Route::get('meetups/{meetup}', [MeetupController::class, 'show']);
        Route::delete('meetups/{meetup}', [MeetupController::class, 'destroy']);
        Route::post('meetups/{meetup}/join', [MeetupController::class, 'join']);
        Route::post('meetups/{meetup}/cancel', [MeetupController::class, 'cancel']);
        Route::post('meetups/{meetup}/arrive', [MeetupController::class, 'arrive']);
        Route::post('meetups/{meetup}/noshow', [MeetupController::class, 'noshow']);
        Route::get('meetups/{meetup}/room', [MeetupController::class, 'room']);
        Route::get('meetups/{meetup}/messages', [MeetupController::class, 'messages']);
        Route::post('meetups/{meetup}/messages', [MeetupController::class, 'send'])->middleware('throttle:chat');
        Route::post('meetups/{meetup}/read', [MeetupController::class, 'read']);

        Route::get('blocks', [SafetyController::class, 'blocks']);
        Route::get('blocks/mine', [SafetyController::class, 'mine']);

        // 알림센터 · 알림 설정 · 푸시 구독
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
        Route::get('me/notification-settings', [NotificationController::class, 'settings']);
        Route::put('me/notification-settings', [NotificationController::class, 'updateSettings']);
        Route::post('push/subscriptions', [NotificationController::class, 'subscribe']);
        Route::delete('push/subscriptions', [NotificationController::class, 'unsubscribe']);
        Route::post('blocks', [SafetyController::class, 'block']);
        Route::delete('blocks/{userId}', [SafetyController::class, 'unblock'])->whereNumber('userId');
        Route::post('reports', [SafetyController::class, 'report']);
    });
});
