# 소셜링앱 DB 설계 — 1차 (광장+채팅)

Nov 10, 2026 · 설계서 v0.1 + 메인 디자인(광장 목록) 기준

## 확정된 결정 (설계서 v0.1과 달라진 점)

| 항목 | 설계서 v0.1 | 1차 결정 | 이유 |
|---|---|---|---|
| 첫 출시 기능 | 모임 | **광장+채팅** | 메인 디자인이 광장 목록 |
| 광장 성격 | 지역/모임 자동 광장 | **사용자가 만드는 주제방** | 디자인의 '광장 만들기'·카테고리 |
| 거리 표시 | 범위만 | 범위만 (`500m 이내` 등) | 디자인의 `200m`는 범위 라벨로 교체 |
| 로그인 | 미정 | **카카오/애플 + PASS 1회** | 가입 시 PASS로 성별·생년 확보 |
| avatars | 2단계 | **1차에 포함** | 광장 카드에 아바타가 보임 |
| plazas | 2단계 | **1차에 포함** | 메인 화면 |

## 테이블 (1차에 생성)

| 테이블 | 핵심 컬럼 | 비고 |
|---|---|---|
| users | id, nickname(UQ), status, region_cell(geohash6), last_seen_at | 성별·나이·이메일·비번 없음 |
| social_accounts | user_id, provider(kakao/apple), provider_user_id | UQ(provider, provider_user_id) |
| user_identities | user_id(PK), ci_hash(UQ), gender, birth_date, provider, verified_at | 수정 API 없음 |
| banned_identities | ci_hash(PK), reason | 영구정지. 탈퇴로 identities가 지워져도 남음 |
| profiles | user_id(PK), bio, interests(JSON) | |
| avatars | user_id(PK), parts(JSON), version | |
| trust_scores | user_id(PK), score(0~1000, 기본 500), attend/noshow/report_count | 산식 비공개 |
| plazas | id, host_id, name, category, region_cell(geohash7), lat, lng, capacity, status | lat/lng는 **격자 중심값**(실제 좌표 아님) |
| chat_rooms | id, type(plaza/meetup/dm/match), ref_id | UQ(type, ref_id) |
| chat_messages | id, room_id, sender_id, type(text/system), body, created_at | 인덱스(room_id, id) |
| blocks | blocker_id, blocked_id | PK 복합. 양방향 적용은 조회 시 처리 |
| reports | reporter_id, target_id, target_type, target_ref_id, reason, status | 누적 시 자동 임시정지 |

## DB에 두지 않는 것 (Redis)

| 키 | 구조 | 용도 |
|---|---|---|
| `plaza:{id}:presence` | ZSET(user_id → 마지막 heartbeat 시각) | 접속자 수·아바타 미리보기. 60초 지나면 이탈 처리 |
| `signup:{token}` | 캐시 15분 | 소셜 로그인 → PASS → 닉네임 사이의 가입 진행 상태 |
| 광장 안 아바타 좌표 | 저장 안 함 | Reverb client event(whisper)로만 전달 |

## 거리 표시 규칙

- 앱이 요청마다 현재 좌표를 보내고, 서버는 **계산에만 쓰고 저장하지 않음** (users에는 geohash6만 저장)
- 응답은 `distance_label`만: `500m 이내` / `1km 이내` / `3km 이내` / `5km 이내`
- 검색 반경 5km, '가까운 순' 정렬

## 2차 (구현됨)

| 테이블 | 핵심 컬럼 | 비고 |
|---|---|---|
| profile_photos | user_id, path, visibility, sort_order, like_count | 0번=대표(항상 공개), 최대 9장, 하트는 비정규화 |
| photo_likes | photo_id, user_id (PK 복합) | 사진당 1회, 다시 누르면 삭제 |
| friend_requests | from_id, to_id, status, message, plaza_id, expires_at | 7일 만료는 조회 조건으로 (크론 없음) |
| friendships | user_id, friend_id (PK 복합), plaza_id | 양방향 2행 |

| chat_room_members | room_id, user_id (PK 복합), last_read_id | DM 참여자·읽음 위치. DM 방은 chat_rooms.ref_id = (작은ID<<32)\|큰ID |

| meetups | plaza_id, host_id, area_label(공개), place_detail(참여자만), starts_at, capacity, age_rule·age_min·age_max, status | 48시간 이내, 2~10명 |
| meetup_members | meetup_id, user_id (PK), status(joined/canceled/late_canceled), arrived_at, noshow_at | |
| noshow_votes | meetup_id, voter_id, target_id (PK) | 상호 확인, 확정 시 trust_scores 감점 1회 |

| admins | name, email(UNIQUE), password | 관리자 (앱 users와 분리) |
| user_sanctions | user_id, admin_id(NULL=시스템), type(suspend/unsuspend/ban), until, reason | 제재 이력 (현재 상태는 users.status) |
| (추가 컬럼) | reports.handled_by·action·admin_note, users.suspended_until, meetups.settled_at | 신고 처리 기록 · 기간 정지 · 출석 정산 완료 |

| notifications | id(uuid), notifiable, data(json), read_at | Laravel 기본 알림 (알림센터) |
| notification_settings | user_id(PK), friend, chat, meetup, night_quiet | 행 없으면 기본값(전부 켬) |
| push_subscriptions | subscribable, endpoint, public_key, auth_token | webpush 패키지 제공, 기기별 1행 |
| (추가 컬럼) | meetups.reminded_at | 1시간 전 알림 1회 |
| (추가 컬럼) | chat_messages.meta(json) | 사진 {path,w,h} · 번개 카드 {meetup_id} |

- 사진 파일: `PHOTO_DISK`(개발 local → 운영 r2/s3). 공개 URL 없음, 30분 서명 URL만 발급
- 업로드 시 EXIF(GPS) 제거: 위치 비공개 원칙 유지
