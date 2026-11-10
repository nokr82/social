<?php

/**
 * 소셜링앱 도메인 설정. 운영 중 바뀔 수 있는 값은 여기 모아둔다.
 */
return [

    // 광장 카테고리: 코드 => 화면 라벨 (디자인 탭 순서)
    'categories' => [
        'talk' => '수다',
        'sports' => '운동',
        'food' => '맛집',
        'game' => '게임',
        'study' => '스터디',
    ],

    'plaza' => [
        'search_radius_m' => 5000,       // 주변 검색 반경
        'default_capacity' => 30,
        'max_capacity' => 100,
        'max_open_per_host' => 3,        // 한 사람이 동시에 열 수 있는 광장 수
        'presence_ttl' => 60,            // heartbeat 없이 이 초가 지나면 이탈로 간주
        'preview_avatars' => 5,          // 카드에 보여줄 아바타 수
    ],

    // 프로필 사진 저장소: 개발은 local(storage/app/private), 운영은 r2·s3 디스크로 교체
    'photos' => [
        'disk' => env('PHOTO_DISK', 'local'),
    ],

    // 아바타 파츠 (앱 디자인의 선택지와 1:1)
    'avatar' => [
        'colors' => ['apricot', 'mint', 'sky', 'pink', 'yellow', 'lavender'],
        'faces' => ['smile', 'grin', 'calm'],
    ],

    // 신고 사유 (디자인 8개 항목과 1:1)
    'report_reasons' => [
        'photo' => '부적절하거나 선정적인 사진',
        'impersonation' => '타인 사진 도용 · 사칭',
        'abuse' => '욕설 · 괴롭힘',
        'sexual' => '원치 않는 성적 메시지',
        'scam' => '돈 요구 · 사기 · 홍보',
        'minor' => '미성년자로 의심돼요',
        'offline' => '번개(오프라인)에서 불쾌한 일',
        'etc' => '기타',
    ],

    // 거리 라벨: [이하 미터, 라벨] — 정밀 거리 대신 범위만 노출 (위치 역추적 방지)
    'distance_buckets' => [
        [500, '500m 이내'],
        [1000, '1km 이내'],
        [3000, '3km 이내'],
        [5000, '5km 이내'],
    ],

    'trust' => [
        'min_score_to_host' => 300,      // 이보다 낮으면 광장 개설 불가
        'auto_suspend_reports' => 5,     // 서로 다른 신고자 수가 이 이상이면 자동 임시정지
    ],

    'signup' => [
        'min_age' => 19,                 // 만 19세 미만 가입 차단
        'token_ttl' => 900,              // 가입 진행 토큰 15분
        // CI 해시용 비밀값. 바꾸면 기존 ci_hash와 비교가 안 되므로 절대 변경 금지
        'ci_pepper' => env('CI_PEPPER'),
    ],

    // 개발용 가짜 드라이버: local/testing에서만 'fake' 사용
    'drivers' => [
        'social' => env('SOCIAL_LOGIN_DRIVER', 'real'),   // real | fake
        'identity' => env('IDENTITY_DRIVER', 'fake'),     // pass | fake (PASS 대행사 계약 전)
        'presence' => env('PRESENCE_DRIVER', 'redis'),    // redis | array
    ],
];
