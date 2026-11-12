# 운영 이미지 (빌드 컨텍스트 = 프로젝트 루트 social/)
#   docker compose -f docker-compose.prod.yml build
# 개발 이미지(docker/php)와 달리 소스를 이미지 안에 굽는다 → 서버에 소스 폴더를 마운트하지 않음

############################ 1) PHP 의존성 (운영용만)
FROM composer:2 AS vendor
WORKDIR /app
COPY api/composer.json api/composer.lock ./
# 확장은 최종 이미지에 있으므로 여기선 검사 생략, 스크립트(artisan)는 소스가 없어 생략
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs --no-interaction
COPY api/ ./
RUN composer dump-autoload --optimize --no-dev --no-scripts

############################ 2) 프런트 빌드 (Vue SPA)
FROM node:22-alpine AS assets
WORKDIR /app
COPY api/package.json api/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY api/ ./
# 브라우저가 접속할 웹소켓 주소는 빌드할 때 JS에 박힌다 → 도메인·키가 바뀌면 다시 빌드
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=443 \
    VITE_REVERB_SCHEME=https
RUN npm run build

############################ 3) 앱 (php-fpm · reverb · queue · scheduler 공용)
FROM php:8.4-fpm AS app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis pcntl bcmath intl zip gd exif opcache gmp \
 && apt-get update \
 && apt-get install -y --no-install-recommends mariadb-client \
 && rm -rf /var/lib/apt/lists/*
    # gmp          : 웹 푸시 암호화(ECDH) 속도
    # mariadb-client: DB 백업(social:backup-db)용 mariadb-dump

COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www
COPY --chown=www-data:www-data api/ ./
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# storage는 볼륨으로 붙지만, 처음 만들어질 때 이 폴더 구조·권한이 복사된다
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
# 패키지 목록 캐시 (APP_ENV=local: 빌드 중엔 운영 안전장치·.env 없이 부팅)
RUN APP_ENV=local php artisan package:discover --ansi

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

############################ 4) 웹 (Caddy: HTTPS 인증서 자동 발급·갱신 + 정적 파일)
FROM caddy:2-alpine AS web
COPY docker/prod/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/public /var/www/public
