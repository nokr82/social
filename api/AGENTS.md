# 소셜링앱 API: 개발 환경 안내

이 Laravel 애플리케이션은 **Docker 컨테이너 안에서만 실행**합니다. 호스트에 PHP나 Composer를 설치하지 마세요.

## 컨테이너 구성

`docker-compose.yml`은 이 폴더의 상위 폴더(프로젝트 루트)에 있습니다. 모든 `docker compose` 명령은 프로젝트 루트에서 실행합니다.

| 서비스 | 역할 | 비고 |
|---|---|---|
| `php` | Laravel API (PHP 8.4-FPM) | 이 `api/` 폴더가 컨테이너의 `/var/www`에 연결됨 |
| `reverb` | WebSocket 서버 (채팅, 광장) | `php`와 같은 이미지 사용 |
| `queue` | 큐 워커 (푸시 알림, 매칭 계산) | `php`와 같은 이미지 사용 |
| `mariadb` | MariaDB 11.8 | 컨테이너 안에서는 호스트 이름 `mariadb`, 호스트 PC에서는 `127.0.0.1:3307` |
| `redis` | Redis 7 (캐시, 세션, 큐) | 컨테이너 안에서 호스트 이름 `redis`로만 접속 가능 |

## 사전 확인

작업을 시작하기 전에 컨테이너가 떠 있는지 확인합니다:

```sh
docker compose ps
```

`php` 컨테이너가 실행 중이 아니면 프로젝트 루트에서 기동합니다:

```sh
docker compose up -d
```

## 명령 실행 규칙

`php`, `composer`, `artisan` 명령은 모두 `php` 컨테이너 안에서 실행합니다:

```sh
docker compose exec php php artisan migrate
docker compose exec php php artisan test
docker compose exec php composer require 패키지명
```

- 컨테이너는 `www-data` 사용자로 실행되며, 호스트 사용자와 UID/GID가 맞춰져 있어 생성된 파일을 호스트에서 바로 편집할 수 있습니다.
- `.env`나 설정을 바꾼 뒤에는 `docker compose exec php php artisan config:clear`로 캐시를 지웁니다.
- 큐 작업이나 브로드캐스트 코드를 바꾼 뒤에는 `docker compose restart queue reverb`로 워커를 재시작해야 반영됩니다.
- 로그 확인: `docker compose logs -f queue reverb`

<laravel-boost-guidelines>
# Laravel 애플리케이션

애플리케이션을 수정하기 전에 Laravel Boost를 설치합니다. 위의 명령 실행 규칙대로 `php` 컨테이너 안에서 실행하세요:

```sh
docker compose exec php composer require laravel/boost --dev
docker compose exec php php artisan boost:install
```

Boost를 설치하면 이 태그 안의 내용이 애플리케이션에 맞춘 가이드라인으로 교체됩니다. 설치 후 이 파일을 다시 읽고, 새로 생성된 가이드라인에 따라 사용자의 원래 요청을 계속 진행하세요.
</laravel-boost-guidelines>
