# 소셜링앱

모임·친목·소개를 하나의 앱에 담은 소셜 서비스입니다. 처음에는 **모임** 기능으로 출시하고, 관계가 깊어지는 순서대로 기능을 열어갑니다. 모든 기능이 하나의 프로필·본인인증·매너점수를 함께 씁니다.

## 관계 깊이 4단계

1. **광장**: 아바타로 가볍게 마주치는 로비
2. **친목**: 1:1 친구 맺기와 DM
3. **모임**: 그룹 단위 정기·번개 활동
4. **소개**: 1:1 이성 소개 (본인이 직접 켜야 활성화)

## 기술 스택

| 구분 | 사용 기술 |
|---|---|
| API | Laravel (PHP 8.4), Sanctum 토큰 인증 |
| 실시간 | Laravel Reverb (WebSocket) |
| 데이터베이스 | MariaDB 11.8 |
| 캐시·큐·위치 검색 | Redis 7 |
| 실행 환경 | Docker Compose + 호스트 Nginx |

## 폴더 구조

```
.
├── api/                    # Laravel API 서버
├── docker/                 # PHP 이미지, Nginx 설정
├── docker-compose.yml      # php, reverb, queue, mariadb, redis
├── .env.example            # Docker 환경 변수 템플릿
├── SETUP.md                # 개발환경 설치 가이드
└── 소셜링앱 설계서 v0.1.md   # 서비스 설계서
```

## 빠른 시작

```bash
cp .env.example .env              # 비밀번호와 UID/GID 수정
cp api/.env.example api/.env      # DB·Redis·Reverb 접속 정보 수정
docker compose up -d
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
```

자세한 설치 방법(호스트 Nginx 연결, hosts 설정 등)은 [SETUP.md](SETUP.md)를 참고하세요.
운영 서버(Lightsail) 배포는 [DEPLOY.md](DEPLOY.md)를 참고하세요.

## 자주 쓰는 명령

| 작업 | 명령 |
|---|---|
| artisan 실행 | `docker compose exec php php artisan ...` |
| 큐·Reverb 로그 | `docker compose logs -f queue reverb` |
| 컨테이너 상태 | `docker compose ps` |
