<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // 카카오 로컬 API (좌표 → 동네 이름). 카카오 로그인과 같은 앱의 'REST API 키'
    // 카카오: 로컬 API(좌표 → 동네) + 카카오 로그인. 같은 앱의 'REST API 키'를 함께 씀
    'kakao' => [
        'rest_key' => env('KAKAO_REST_API_KEY'),
        'client_secret' => env('KAKAO_CLIENT_SECRET'),   // 카카오 로그인 > 보안 > Client Secret (사용함일 때)
        // 카카오 콘솔 '리다이렉트 URI'와 정확히 같아야 함
        'redirect_uri' => env('KAKAO_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/auth/kakao'),
        // 카카오맵 JavaScript 키 (친구 지도). 브라우저에 노출되는 값이라 비밀 아님 — 대신 카카오 콘솔 '플랫폼 > Web'에 도메인 등록 필수
        'js_key' => env('KAKAO_JS_KEY'),
    ],

    // 애플 로그인: identity_token의 aud(앱 번들 ID) 검증용
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
