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

## 안전

| 메서드 | 경로 | 입력 |
|---|---|---|
| GET | /api/blocks | — (양방향 ID 목록) |
| POST | /api/blocks | user_id |
| DELETE | /api/blocks/{userId} | — |
| POST | /api/reports | user_id, target_type(user/message/plaza), target_ref_id?, reason(meta 참고), detail?, block?(기본 true) — 서로 다른 신고자 5명이면 자동 임시정지 |

## 앱 (Vue 모바일 웹)

`resources/js/app/` — 화면 4개(온보딩·메인·광장·신고). 빌드: `npm install && npm run build`, 접속: `http://social.local`
