# 소셜링앱 API — 1차

공통: `Authorization: Bearer {token}` · 에러는 `{code, message}` (앱은 code로 분기, message는 그대로 표시)

## 가입·로그인

| 메서드 | 경로 | 입력 | 응답 |
|---|---|---|---|
| POST | /api/auth/social | provider(kakao/apple), token | 기존: `{status:"login", token, user}` / 신규: `{status:"signup", signup_token}` |
| POST | /api/auth/identity | signup_token, transaction_id | `{status, gender, age}` · UNDER_AGE, BANNED, ALREADY_REGISTERED |
| POST | /api/auth/register | signup_token, nickname(한글·영문·숫자 2~10), avatar?{color, face} | `{token, user}` |
| POST | /api/auth/logout | — | 현재 기기 토큰 폐기 |

개발용: `SOCIAL_LOGIN_DRIVER=fake` → token `fake:아무ID` / `IDENTITY_DRIVER=fake` → transaction_id `fake:{CI}:{M|F}:{YYYYMMDD}`

## 내 정보

| 메서드 | 경로 | 입력 |
|---|---|---|
| GET | /api/me | — (본인에게만 gender·age 노출) |
| PATCH | /api/me/profile | bio?, interests[]? |
| PUT | /api/me/location | lat, lng → geohash6만 저장 |
| PUT | /api/me/avatar | parts{color(apricot/mint/sky/pink/yellow/lavender), face(smile/grin/calm)} |

## 광장 (메인 화면)

| 메서드 | 경로 | 입력 | 비고 |
|---|---|---|---|
| GET | /api/meta/categories | — | 카테고리 |
| GET | /api/meta/report-reasons | — | 신고 사유 8개 |
| GET | /api/plazas | lat, lng, category?, q?, sort?(near/busy/new), page? | `{hot, data[], meta}` — 정렬 탭 3개 |
| POST | /api/plazas | name, category, description?, capacity?, lat, lng | 만든 사람 자동 입장 |
| GET | /api/plazas/{id} | lat?, lng? | |
| POST | /api/plazas/{id}/enter | — | `{channel, heartbeat_interval}` · PLAZA_FULL |
| POST | /api/plazas/{id}/heartbeat | — | 광장 화면에 있는 동안 30초마다 |
| POST | /api/plazas/{id}/leave | — | |
| GET | /api/plazas/{id}/messages | before_id? | 최신 50개, 차단 관계 메시지 제외 |
| POST | /api/plazas/{id}/messages | body(≤500) | 분당 30개 제한 |

광장 카드 필드: `name, category_label, distance_label("500m 이내"), live_count, is_live, preview_avatars[], last_message(10분 내 말풍선), capacity`

## 실시간 (Reverb)

1. `POST /api/plazas/{id}/enter` 성공 후
2. Echo로 `presence-plaza.{id}` 구독 (인증 엔드포인트: `POST /api/broadcasting/auth`, Bearer 토큰)
3. 수신 이벤트 `.message.sent` / 아바타 이동은 `client-move` whisper로 앱끼리 직접 (서버 저장 없음)
4. 실시간 메시지는 앱이 `GET /api/blocks` 목록으로 한 번 더 거름

## 사진·프로필·친구 (2차)

| 메서드 | 경로 | 입력 / 비고 |
|---|---|---|
| GET | /api/me/photos | 내 사진 전부 (url은 30분 서명 URL) |
| POST | /api/me/photos | multipart `photo` (≤10MB, 최대 9장) — EXIF 제거·1280px 재인코딩 |
| PATCH | /api/me/photos/{id} | visibility(public/private) · 대표(첫 장)는 비공개 불가 |
| DELETE | /api/me/photos/{id} | 다음 사진이 대표가 되면 자동 공개 |
| POST | /api/photos/{id}/like | 하트 토글 → `{liked, like_count}` |
| GET | /api/users/{id} | relation(self/none/sent/received/friends), 공개 사진, 비공개 수 · 친구면 성별·나이·비공개 사진 |
| GET | /api/friend-requests?type=received\|sent | 보낸 요청은 거절돼도 pending으로 보임 |
| POST | /api/friend-requests | user_id, message?, plaza_id? (하루 30건) |
| POST | /api/friend-requests/{id}/accept · reject · undo | 수락 응답에 상대 성별·나이·비공개 사진 |
| DELETE | /api/friend-requests/{id} | 보낸 사람 취소 |

`/api/me` 응답에 `stats{hearts, friends, received_requests, photos}` 추가 · 차단하면 친구 관계·요청 자동 삭제

## 채팅 탭 · 1:1 대화 (친구끼리만)

| 메서드 | 경로 | 입력 / 비고 |
|---|---|---|
| GET | /api/chats | 대화 목록: 상대·마지막 메시지·unread, `unread_total` |
| GET | /api/friends | 친구 목록: 성별·나이·online·plaza_name(지금 있는 광장)·last_seen_at |
| POST | /api/dm/{userId} | 친구와의 방 열기(없으면 생성) → `{room_id}` · 친구 아니면 403 NOT_FRIENDS |
| GET | /api/chats/{room} | 헤더 정보 + met_at(만난 광장) + other_last_read_id('읽음' 표시) |
| GET/POST | /api/chats/{room}/messages | before_id? / body(≤1000) |
| POST | /api/chats/{room}/read | last_id — 읽음 위치는 앞으로만 이동 |

실시간: `private-chat.{room}` → `.dm.sent`, `.dm.read` / `private-App.Models.User.{me}` → `.dm.sent` (목록·배지)
접속 상태: 인증 요청마다 `last_seen_at` 갱신(1분 1회), 5분 내 활동 = 접속 중

## 번개

| 메서드 | 경로 | 입력 / 비고 |
|---|---|---|
| POST | /api/plazas/{id}/meetups | title, area_label, place_detail, starts_at(ISO, 48시간 이내), capacity(2~10), age_rule(any/20s/30s/near), description? |
| GET | /api/plazas/{id}/meetups | 광장 배너용 (시작 전) |
| GET | /api/meetups?lat&lng | 번개 탭 '내 주변' (5km) |
| GET | /api/meetups/mine | 내 번개 + last_message·unread (번개 탭·채팅 탭) |
| GET | /api/meetups/{id} | place_detail은 참여자만, condition은 문구(호스트 나이 노출 방지) |
| POST | /api/meetups/{id}/join | 즉시 확정 · AGE_MISMATCH / MEETUP_FULL / NOT_AVAILABLE(차단 관계) |
| POST | /api/meetups/{id}/cancel | 참여 취소 → canceled(1시간 전까지) / late_canceled |
| DELETE | /api/meetups/{id} | 호스트 번개 취소 |
| POST | /api/meetups/{id}/arrive | 도착 토글 (약속 2시간 전부터, 표시용) |
| POST | /api/meetups/{id}/noshow | user_id — 약속 시간~종료 사이, 2표(2인 번개는 1표)면 노쇼 확정·감점 1회 |
| GET | /api/meetups/{id}/room | 채팅방 카드: 정확한 장소·도착 현황·can_vote_noshow |
| GET/POST | /api/meetups/{id}/messages · POST /read | 참여자만, 약속 24시간 뒤 닫힘 |

실시간: `private-meetup.{id}` → `.meetup.message` (일반·안내 메시지)

## 안전

| 메서드 | 경로 | 입력 |
|---|---|---|
| GET | /api/blocks | — (양방향 ID 목록) |
| GET | /api/blocks/mine | — MY 차단 목록 (내가 차단한 사람만: user{id,nickname,avatar}, blocked_at) |
| POST | /api/blocks | user_id |
| DELETE | /api/blocks/{userId} | — |
| POST | /api/reports | user_id, target_type(user/message/plaza), target_ref_id?, reason(meta 참고), detail?, block?(기본 true) — 서로 다른 신고자 5명이면 자동 임시정지 |

- 정지 계정은 모든 API가 403 `{code: ACCOUNT_SUSPENDED, message, until}` (until 지나면 다음 요청에서 자동 해제)

## 알림

| 메서드 | 경로 | 입력 / 비고 |
|---|---|---|
| GET | /api/notifications?page= | 30개씩 {data[{id,kind,title,body,url,actor,read,created_at}], has_more, unread} |
| POST | /api/notifications/{id}/read · /read-all | 읽음 → {unread} |
| GET·PUT | /api/me/notification-settings | friend, chat, meetup, night_quiet (푸시에만 적용) + push_devices |
| POST·DELETE | /api/push/subscriptions | {endpoint(https), keys{p256dh,auth}, content_encoding?} / ?endpoint= |
| GET | /api/meta/push | VAPID 공개키 |

- 알림센터+푸시: 친구 요청·수락, 번개 참여(호스트), 호스트 취소, 1시간 전(`meetups:remind` 5분마다)
- 푸시만: DM, 번개방 메시지 (tag로 방당 1개로 덮어씀)
- 실시간: 개인 채널 `App.Models.User.{id}`의 Laravel 알림 이벤트 → 종 숫자 +1
- `/me`의 stats.unread_notifications = 종 숫자

## 운영 (관리자 · 예약 작업)

- 관리자는 앱 사용자와 분리된 `admins` 테이블 + `admin` 세션 가드 (Filament 관리 화면 전용)
- 관리자 만들기: `php artisan social:make-admin 메일 --name=이름` (비밀번호는 입력 프롬프트)
- 관리자 화면 `/admin` (Filament 5): 신고(승인·반려), 회원(정지·해제·영구정지), 제재 이력(읽기 전용). 처리는 모두 ModerationService 경유
- 처리 로직: `ModerationService` — 신고 승인(none/warn/suspend/ban)·반려, 정지·해제, 영구정지(CI 차단 명단 등록)
  - 신고 승인 시 매너점수 -50, 반려 시 시스템 자동 정지였다면 기준 미달 시 자동 해제
- `meetups:settle` 매시간: 채팅방이 닫힌 번개의 참석자 attend_count +1, 매너점수 +5 (최대 1000), 한 번만

## 앱 (Vue 모바일 웹)

`resources/js/app/` — 화면 4개(온보딩·메인·광장·신고). 빌드: `npm install && npm run build`, 접속: `http://social.local`
