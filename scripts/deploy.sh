#!/usr/bin/env bash
# 서버에서 실행되는 배포 스크립트 (GitHub Actions가 SSH로 호출)
# 사용: bash scripts/deploy.sh <커밋SHA>
set -euo pipefail          # 한 단계라도 실패하면 즉시 중단

APP_DIR=/srv/social
PUBLIC=$APP_DIR/api/public
SHA=${1:-}
cd "$APP_DIR"

echo "▶ 1. 코드 받기"
git fetch --quiet origin main
# --ff-only: 서버에서 누가 파일을 직접 고쳤으면 덮어쓰지 않고 실패 → 사고 방지
git merge --ff-only origin/main
if [[ -n "$SHA" && "$(git rev-parse HEAD)" != "$SHA" ]]; then
  echo "경고: 서버 HEAD($(git rev-parse --short HEAD))가 배포 요청 커밋(${SHA:0:7})과 다름"
fi

echo "▶ 2. 이미지 갱신 (Dockerfile 바뀐 경우에만 실제 빌드됨)"
docker compose build --quiet
docker compose up -d --remove-orphans

echo "▶ 3. PHP 의존성"
docker compose exec -T php composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "▶ 4. DB 마이그레이션"
docker compose exec -T php php artisan migrate --force

echo "▶ 5. 프론트 빌드 교체 (build_new → build, 거의 순간 교체)"
if [[ -d $PUBLIC/build_new ]]; then
  chmod -R u+rwX,go+rX,go-w "$PUBLIC/build_new"   # Nginx(www-data)가 읽을 수 있게
  rm -rf "$PUBLIC/build_old"
  [[ -d $PUBLIC/build ]] && mv "$PUBLIC/build" "$PUBLIC/build_old"
  mv "$PUBLIC/build_new" "$PUBLIC/build"
fi

echo "▶ 6. 캐시 갱신 (config·route·view·event)"
docker compose exec -T php php artisan optimize:clear
docker compose exec -T php php artisan optimize

echo "▶ 7. 프로세스 재시작"
docker compose exec -T php kill -USR2 1          # PHP-FPM 무중단 리로드 (opcache 비움)
docker compose exec -T php php artisan queue:restart    # 처리 중인 작업 끝난 뒤 재시작
docker compose exec -T php php artisan reverb:restart   # restart 정책으로 자동 재기동
docker compose restart scheduler

echo "▶ 8. 헬스체크"
sleep 3
if curl -fsS -o /dev/null http://127.0.0.1/up -H "Host: 54.116.246.201"; then
  echo "✅ 배포 완료: $(git log -1 --format='%h %s')"
else
  echo "❌ /up 응답 실패 - docker compose logs 확인 필요"
  exit 1
fi
