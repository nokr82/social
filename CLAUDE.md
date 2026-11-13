# 소셜링앱: 작업 안내 (Claude용)

위치 기반 소셜 앱입니다. 기능은 광장, 동네 광장, 친구·DM, 모임, 친구 지도로 구성됩니다.
- 실행 규칙: [api/CLAUDE.md](api/CLAUDE.md)
- 배포: [DEPLOY.md](DEPLOY.md)
- 기획: `소셜링앱 설계서 v0.1.md`

## 작업 원칙
- 답변·주석·커밋 메시지는 **한국어**로 씁니다. 결론을 먼저 말하고, 제안할 때는 추천 1개와 대안 1개를 함께 냅니다.
- **설계를 먼저 공유한 뒤 구현합니다.** 모르는 것은 모른다고 하고, 위험한 방향이면 바로 지적합니다.
- 코드에는 "왜"를 설명하는 주석을 답니다. 기존 파일의 주석 스타일을 따릅니다.
- 실수를 고칠 때는 재발 방지책을 함께 제시합니다. 재발 방지책이란 테스트, 부팅 가드, 로그 같은 장치입니다.

## 구조
```
api/                 Laravel 13 (PHP 8.4) + Vue 3 SPA (같은 프로젝트)
  app/Services/<도메인>/   비즈니스 로직은 여기에 둠 (컨트롤러는 검증·응답만)
  app/Http/Controllers/Api/  JSON API (routes/api.php, auth:sanctum + active)
  app/Filament/        관리자 화면 (/admin)
  resources/js/app/    Vue SPA: pages/, components/, lib/(api·echo·toast 등 공용)
docker/              PHP 이미지, Nginx, 운영 env 예시
scripts/deploy.sh    운영 배포 (서버에서 실행)
```

## 핵심 규칙
- **오류**: `App\Exceptions\DomainException('CODE', '사용자용 한국어 메시지', status)`로 던집니다. 프론트는 `ApiError.message`를 토스트로 띄웁니다.
- **알림**: `App\Notifications\Notify::xxx()`에 모읍니다. 그룹(`friend`/`chat`/`meetup`)이 사용자의 푸시 설정을 따릅니다.
- **실시간**:
  - Reverb를 씁니다. 채널 인증은 `routes/channels.php`, 인증 엔드포인트는 `/api/broadcasting/auth`입니다.
  - 프론트는 `connectEcho().private(name).listen('.이벤트명')`으로 구독하고, 화면을 떠날 때 `window.Echo.leave()`를 부릅니다.
- **외부 의존은 드라이버로 교체합니다** (`config/social.php`의 `drivers`).
  - `social`: real | fake
  - `identity`: self | pass | fake
  - `region`: boundary | kakao | fake
  - `presence`: redis | array
  - 운영에서 fake를 쓰면 `AppServiceProvider`가 부팅을 막습니다. 새 드라이버를 추가할 때도 이 가드를 함께 넣습니다.
- **동네(region)**:
  - 시·군·구 단위입니다. 자체 경계(`region_boundaries`, `php artisan regions:import`)로 판단합니다.
  - 코드는 `SGG-11680` 형식입니다.
- **위치 공유**:
  - 좌표는 DB에 저장하지 않고 캐시에 마지막 1개만 15분 동안 둡니다.
  - 열람 기록(`location_access_logs`)과 열람 통보는 법적 요건이라 빼면 안 됩니다.
- **차단**: `FriendService::severAll()`에서 친구, 요청, 위치 공유를 한꺼번에 정리합니다. 관계에 묶인 기능을 새로 만들면 여기에 정리 로직을 추가합니다.
- **프라이버시**: 차단 여부를 드러내지 않습니다. 차단당한 쪽에도 "친구 아님"과 같은 응답을 줍니다.

## 자주 걸린 함정
- **Filament 클로저**는 인자를 **이름**으로 주입합니다. `$query`, `$record`, `$state`, `$data`만 쓰고, `$q` 같은 이름은 쓰지 않습니다.
- **`.env`나 설정을 바꾼 뒤**:
  - 개발: `config:clear`
  - 운영: `deploy.sh`로 `optimize`를 다시 실행
  - 큐나 브로드캐스트 코드가 바뀌었으면 queue와 reverb를 재시작합니다.
- **개발 VM의 hgfs 공유 폴더**:
  - npm은 `--no-bin-links`로 실행하고, 빌드는 `node node_modules/vite/bin/vite.js build`로 합니다.
  - Windows에서 받은 node_modules에는 Linux용 rolldown 바인딩이 없습니다. `@rolldown/binding-linux-x64-gnu`를 `--no-save`로 따로 설치합니다.
- **운영 서버**:
  - `docker-compose.prod.yml`은 서버의 override 파일입니다. 서버 전용 설정을 덮어쓰지 않게 주의합니다.
  - `deploy.sh`는 `--ff-only`로 받으므로, 서버에서 파일을 직접 고치면 배포가 실패합니다.
- **위치 권한**은 HTTPS나 localhost에서만 동작합니다. 개발 VM에 IP로 접속하면 지도와 위치 기능을 시험할 수 없습니다.
- **`.env*` 파일은 원격 도구로 쓸 수 없습니다.** 예시 파일(`*.example`)만 수정하고, 실제 값은 사용자가 넣습니다.

## 테스트
- `docker compose exec php php artisan test` 또는 `--filter=클래스명`으로 실행합니다. DB는 RefreshDatabase를 씁니다.
- 기능을 추가하면 `tests/Feature/<기능>Test.php`에 다음을 함께 넣습니다.
  - 정상 흐름
  - 권한 경계: 친구 아님, 차단, 정지 계정
  - 만료나 시간 경과(`$this->travel()`)
- 외부 API는 fake 드라이버나 `Http::fake()`로 대체합니다. 테스트에서는 실제로 호출하지 않습니다.
