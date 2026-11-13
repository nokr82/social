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
        'host_grace' => 180,             // 방장이 나간 뒤 이 초 안에 안 돌아오면 위임 또는 닫기 (프로필 보기·새로고침 정도는 버팀)
        'kick_daily_limit' => 10,        // 광장 하나에서 24시간 동안 강퇴할 수 있는 인원 (방장 권한 남용 방지). 강퇴는 방장이 해제할 때까지 유지
        'kick_reasons' => [
            'spam' => '도배',
            'abuse' => '욕설 · 비방',
            'etc' => '기타',
        ],
        'closed_message_days' => 30,     // 닫힌 광장의 대화 보관 기간 (신고된 메시지는 증거로 계속 보관)
        'preview_avatars' => 5,          // 카드에 보여줄 아바타 수
    ],

    // 프로필 사진 저장소: 개발은 local(storage/app/private), 운영은 r2·s3 디스크로 교체
    'photos' => [
        'disk' => env('PHOTO_DISK', 'local'),
    ],

    // 번개
    'meetup' => [
        'max_open_per_host' => 3,        // 동시에 열 수 있는 번개 수
        'search_radius_m' => 5000,       // 번개 탭 '내 주변'
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
        'report_penalty' => 50,          // 신고 승인 시 매너점수 감점
        'attend_bonus' => 5,             // 번개 정상 참석 시 가점 (최대 1000)
        'noshow_penalty' => 30,          // 노쇼 확정 시 매너점수 감점 (0~1000 중)
        'noshow_votes' => 2,             // 노쇼 확정에 필요한 '안 왔어요' 표 (참여자가 적으면 그보다 적게)
    ],

    'signup' => [
        'min_age' => 19,                 // 만 19세 미만 가입 차단
        'token_ttl' => 900,              // 가입 진행 토큰 15분
        // CI 해시용 비밀값. 바꾸면 기존 ci_hash와 비교가 안 되므로 절대 변경 금지
        'ci_pepper' => env('CI_PEPPER'),
    ],

    // 개발용 가짜 드라이버: local/testing에서만 'fake' 사용
    // 동네 상시 광장: 법정동마다 1개. 구역(관심사)별·채널(30명)별로 따로 보이고 대화한다
    'town' => [
        'channel_capacity' => 30,        // 한 채널 최대 인원. 차면 다음 채널로 자동 배정
        'max_channels' => 10,            // 구역당 최대 채널 (구역당 300명)
        'preview_avatars' => 9,          // 메인 카드 풍경에 그릴 아바타 수
        // 순서 = 상시 광장 지도의 구역 버튼 순서 (디자인 TownPlaza.dc.html)
        'zones' => [
            'fountain' => ['name' => '분수대', 'topic' => '아무 얘기'],
            'bench' => ['name' => '벤치', 'topic' => '조용히 쉬기'],
            'playground' => ['name' => '놀이터', 'topic' => '게임'],
            'foodtruck' => ['name' => '푸드트럭', 'topic' => '맛집'],
            'field' => ['name' => '운동장', 'topic' => '운동'],
        ],
    ],

    // 동네 경계 데이터 (REGION_DRIVER=boundary, `php artisan regions:import`로 적재)
    // 새 버전: https://github.com/vuski/admdongkor 의 verYYYYMMDD 폴더 → 날짜만 바꾸고 다시 적재
    // 출처 표시 의무: 통계청 SGIS 행정동 경계(공공누리 1유형), 가공 vuski/admdongkor (CC BY 4.0)
    'region_boundary' => [
        'url' => env('REGION_BOUNDARY_URL', 'https://raw.githubusercontent.com/vuski/admdongkor/master/ver20261001/HangJeongDong_ver20261001.geojson'),
    ],

    // 관리자 화면 접근 허용 IP (쉼표 구분, 비우면 제한 없음)
    'admin_allowed_ips' => env('ADMIN_ALLOWED_IPS', ''),

    'drivers' => [
        'social' => env('SOCIAL_LOGIN_DRIVER', 'real'),   // real | fake
        // pass  : PASS 대행사 (계약 후)
        // self  : 비공개 베타용 직접 입력 (성별·출생연도 + 만 19세 확인). '인증 전'으로 표시, 정지는 카카오 계정 단위
        // fake  : 개발·테스트용 (운영 부팅 차단)
        'identity' => env('IDENTITY_DRIVER', 'fake'),
        'presence' => env('PRESENCE_DRIVER', 'redis'),    // redis | array
        // boundary : 서버에 적재한 경계로 시·군·구 판단 (무료·무제한, 운영 기본)
        // kakao    : 카카오 로컬 API로 법정동 판단 (카카오맵 사용 설정 필요)
        // fake     : 개발·테스트용 (운영 부팅 차단)
        'region' => env('REGION_DRIVER', 'fake'),
    ],
];
