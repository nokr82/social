#!/bin/sh
# 컨테이너 시작 시: 환경변수가 주입된 상태에서 설정·라우트 캐시 생성 (빌드 시점에는 .env가 없음)
set -e

php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan view:cache --quiet
php artisan event:cache --quiet
php artisan filament:optimize --quiet 2>/dev/null || true   # 관리자 화면 컴포넌트 캐시 (없어도 동작)

exec "$@"
