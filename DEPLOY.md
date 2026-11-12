# 운영 배포 (Lightsail 2GB · 서울 · social.devhong.shop)

구조: `web`(Caddy, HTTPS 자동) → `php` / `reverb`, 같은 이미지로 `queue`·`scheduler`, 내부망 `mariadb`·`redis`.
개발환경(`docker-compose.yml`)은 그대로 두고 운영은 `docker-compose.prod.yml`만 쓴다.

## 0. 준비 (콘솔 작업, 한 번)

| 어디서 | 할 일 |
|---|---|
| Lightsail 인스턴스 | Ubuntu 24.04 · 2GB · 서울. **고정 IP** 연결. 네트워킹 방화벽에 TCP 80·443, UDP 443 열기 |
| DNS | `social.devhong.shop` A 레코드 → 고정 IP |
| Lightsail 버킷 | 서울 리전에 **비공개** 버킷 생성 → 권한 → **액세스 키 만들기** (키·시크릿 보관) |
| 카카오 디벨로퍼스 | 플랫폼 > Web 사이트 도메인 `https://social.devhong.shop` / 카카오 로그인 활성화 / 리다이렉트 URI `https://social.devhong.shop/auth/kakao` / (선택) 보안 > Client Secret |
| 개발 PC | `docker compose exec php composer require league/flysystem-aws-s3-v3 "^3.0"` → composer.json·lock 커밋 (버킷 저장용 패키지) |

## 1. 서버 기본 설정 (SSH, 한 번)

```bash
# 스왑 2GB: 빌드(npm·composer) 중 메모리 부족으로 죽는 것 방지
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER && newgrp docker
```

## 2. 코드·환경 변수

```bash
git clone <저장소 주소> social && cd social
cp docker/prod/compose-vars.example .env      # docker-compose.prod.yml 변수
cp docker/prod/laravel-env.example api/.env    # Laravel 운영 설정
```

값 만들기 (모두 개발 서버와 **다른 새 값**):

```bash
openssl rand -hex 24                      # DB·Redis 비밀번호, REVERB_APP_SECRET
openssl rand -hex 10                      # REVERB_APP_KEY (social/.env와 api/.env에 같은 값), REVERB_APP_ID는 아무 숫자
openssl rand -hex 32                      # CI_PEPPER (운영 시작 후 절대 변경 금지)
echo "base64:$(openssl rand -base64 32)"  # APP_KEY
```

- `social/.env`와 `api/.env`의 DB·Redis 비밀번호를 서로 같게
- 버킷 이름·액세스 키 → `api/.env`의 `AWS_*`
- 카카오 REST API 키·Client Secret → `KAKAO_*`

## 3. 첫 실행

```bash
docker compose -f docker-compose.prod.yml up -d --build      # 첫 빌드 5~10분
alias dc='docker compose -f docker-compose.prod.yml'

dc exec php php artisan webpush:vapid --show   # 출력된 VAPID 두 줄을 api/.env에 넣고 ↓
dc up -d                                       # 환경 변수 다시 읽기
dc exec php php artisan migrate --force
dc exec php php artisan social:make-admin 메일 --name=이름
```

확인: `https://social.devhong.shop/up` → 200, 카카오로 가입, `/admin` 로그인.

## 4. 업데이트 배포

```bash
git pull
dc up -d --build                       # 새 이미지로 교체 (설정 캐시는 컨테이너 시작 때 자동 생성)
dc exec php php artisan migrate --force
```

`api/.env`만 바꿨으면 `dc up -d`로 충분. 단 `REVERB_APP_KEY`·도메인이 바뀌면 프런트를 다시 빌드해야 하므로 `--build`.

## 5. 백업·운영

- DB: 매일 03:30 자동 → 버킷 `backups/db/` (14일 보관). 수동: `dc exec php php artisan social:backup-db`
- 복구: `gunzip -c 백업.sql.gz | dc exec -T mariadb mariadb -u root -p<루트비번> social`
- 서버 전체: Lightsail 인스턴스 > 스냅샷 > **자동 스냅샷** 켜기
- 로그: `dc logs -f php queue` / 앱 로그는 `storage/logs` (14일)
- 관리자 화면을 내 IP에서만 열리게: `api/.env`의 `ADMIN_ALLOWED_IPS` → `dc up -d`

## 6. 베타 운영 주의

- `IDENTITY_DRIVER=self`: 성별·나이를 본인이 입력, 프로필에 '인증 전' 표시. 영구정지는 카카오 계정 단위라 **다른 카카오 계정 재가입은 못 막음** → 초대한 사람만 쓰는 비공개 베타로 운영
- PASS 계약 후 `IDENTITY_DRIVER=pass`로 바꾸고 기존 가입자는 재인증 필요
