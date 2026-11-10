# 소셜링앱 개발환경 설치

## 1. 폴더 배치
```bash
sudo mkdir -p /srv/social && sudo chown $USER:$USER /srv/social
# 이 세트를 /srv/social 에 복사
cd /srv/social
mkdir api
cp .env.example .env      # 비밀번호 변경, UID/GID는 `id -u` / `id -g` 값
```
> `/srv`를 쓰는 이유: Ubuntu 홈 폴더는 권한이 750이라 호스트 Nginx(www-data)가 `public/`을 읽지 못함

## 2. 기반 컨테이너 기동 (Laravel 설치 전이라 reverb/queue는 아직 제외)
```bash
docker compose build
docker compose up -d php mariadb redis
```

## 3. Laravel 설치
```bash
docker compose exec php composer create-project laravel/laravel .
docker compose exec php php artisan install:api            # Sanctum 토큰 인증
docker compose exec php php artisan install:broadcasting   # Reverb 선택
```

## 4. `api/.env` 수정
```ini
APP_URL=http://social.local

DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=social
DB_USERNAME=social
DB_PASSWORD=(루트 .env 값)

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=(루트 .env 값)
REDIS_PORT=6379

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb

# Reverb: 서버는 컨테이너 내부 8080, 클라이언트는 Nginx(80) 경유
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_HOST=social.local
REVERB_PORT=80
REVERB_SCHEME=http
```
```bash
docker compose exec php php artisan config:clear
docker compose exec php php artisan migrate
docker compose up -d          # reverb, queue 포함 전체 기동
```

## 5. 호스트 Nginx 연결
```bash
sudo cp docker/nginx/social.conf /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/social.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
echo "127.0.0.1 social.local" | sudo tee -a /etc/hosts
```
Windows에서 접속하려면 `C:\Windows\System32\drivers\etc\hosts`에 `<VM IP> social.local` 추가

## 6. 확인
```bash
docker compose ps                    # 5개 모두 Up
curl -I http://social.local          # 200
docker compose exec php php artisan tinker --execute="Redis::ping();"
```

## 자주 쓰는 명령
| 작업 | 명령 |
|---|---|
| artisan | `docker compose exec php php artisan ...` |
| 로그 | `docker compose logs -f queue reverb` |
| 코드 변경 후 큐 재시작 | `docker compose restart queue` |
| DB 툴 접속 | `127.0.0.1:3307` (VM 안) |
