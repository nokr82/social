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

## 안전

| 메서드 | 경로 | 입력 |
|---|---|---|
| GET | /api/blocks | — (양방향 ID 목록) |
| POST | /api/blocks | user_id |
| DELETE | /api/blocks/{userId} | — |
| POST | /api/reports | user_id, target_type(user/message/plaza), target_ref_id?, reason(meta 참고), detail?, block?(기본 true) — 서로 다른 신고자 5명이면 자동 임시정지 |

## 앱 (Vue 모바일 웹)

`resources/js/app/` — 화면 4개(온보딩·메인·광장·신고). 빌드: `npm install && npm run build`, 접속: `http://social.local`
