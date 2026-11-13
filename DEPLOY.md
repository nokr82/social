# 운영 배포 (Lightsail 2GB · 서울 · social.devhong.shop)

서버는 개발과 같은 `docker-compose.yml` + 운영 덮어쓰기 `docker-compose.prod.yml`, 앞단은 **호스트 Nginx**.
배포는 `scripts/deploy.sh` 하나로 한다 (코드 받기 → 이미지 → composer → 마이그레이션 → 캐시 → 무중단 리로드).

```bash
cd /srv/social
bash scripts/deploy.sh           # 백엔드만
bash scripts/deploy.sh --front   # 프론트(Vue)까지 서버에서 빌드
```

## 출시 준비 체크리스트 (한 번)

| 순서 | 할 일 |
|---|---|
| 1 | DNS: `social.devhong.shop` A 레코드 → 서버 고정 IP |
| 2 | HTTPS: 호스트 Nginx의 `server_name`을 도메인으로 바꾸고 `sudo certbot --nginx -d social.devhong.shop` (자동 갱신 포함) |
| 3 | Lightsail 버킷: 서울 리전 **비공개** 버킷 + 액세스 키 |
| 4 | 카카오 디벨로퍼스: Web 도메인 `https://social.devhong.shop`, 카카오 로그인 활성화, 리다이렉트 URI `https://social.devhong.shop/auth/kakao` |
| 5 | 개발 PC: `docker compose exec php composer require league/flysystem-aws-s3-v3 "^3.0"` → composer.json·lock 커밋·푸시 |
| 6 | 서버 `api/.env`: `docker/laravel-env.prod.example`의 값 반영 (기존 DB·Redis·Reverb·APP_KEY·CI_PEPPER는 유지) |
| 7 | PHP 이미지 갱신(백업용 mariadb-dump·gmp 추가): `docker compose build php && docker compose up -d` |
| 8 | `bash scripts/deploy.sh --front` → `https://social.devhong.shop/up` 200 확인 → 카카오 가입 시험 |
| 9 | 동네 경계 적재(처음 한 번, 약 1분): `docker compose exec php php artisan regions:import` → 메인 화면에 '○○구 광장' 확인 |

`deploy.sh` 8단계 헬스체크는 지금 IP로 확인한다. HTTPS 전환 후에는 `Host:` 값을 도메인으로 바꾸기.

## 백업·운영

- DB: 매일 03:30 자동 → 버킷 `backups/db/` (14일 보관). 수동: `docker compose exec php php artisan social:backup-db`
- 복구: `gunzip -c 백업.sql.gz | docker compose exec -T mariadb mariadb -u root -p social`
- 서버 전체: Lightsail > 스냅샷 > **자동 스냅샷** 켜기
- 동네 경계 갱신(행정구역 개편 시, 연 1~2회 확인): [admdongkor](https://github.com/vuski/admdongkor) 최신 `verYYYYMMDD` 폴더 → `api/.env`에 `REGION_BOUNDARY_URL=` 지정 → `regions:import` 재실행. 경계 데이터 출처 표시(통계청 SGIS·vuski/admdongkor, CC BY 4.0)를 앱 정보 화면에 유지
- 관리자 화면 IP 제한: `api/.env`의 `ADMIN_ALLOWED_IPS` → `bash scripts/deploy.sh`

## 베타 운영 주의

- `IDENTITY_DRIVER=self`: 성별·나이를 본인이 입력, 프로필에 '인증 전'. 정지는 카카오 계정 단위라 다른 카카오 계정 재가입은 못 막음 → 초대한 사람만 쓰는 비공개 베타
- PASS 계약 후 `IDENTITY_DRIVER=pass`, 기존 가입자는 재인증
