# Laravel용 PHP 8.4-FPM (Debian 기반: alpine보다 확장 빌드 문제가 적음)
FROM php:8.4-fpm

# 확장 설치 도우미 (의존 패키지까지 자동 설치)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
      pdo_mysql \
      redis \
      pcntl \
      bcmath \
      intl \
      zip \
      gd \
      exif \
      opcache
    # pdo_mysql : MariaDB 접속
    # redis     : phpredis (Laravel REDIS_CLIENT=phpredis)
    # pcntl     : queue:work / reverb 의 정상 종료 신호 처리
    # bcmath    : 금액·좌표 정밀 계산
    # intl      : 다국어·날짜 포맷
    # zip       : composer 설치 속도
    # gd, exif  : 프로필·아바타 이미지 처리 (exif: 사진 회전 정보)

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# www-data 홈이 /var/www라서 캐시가 프로젝트 폴더에 생기는 것 방지
# (create-project는 빈 폴더가 필요)
ENV COMPOSER_HOME=/tmp/composer

# www-data의 UID/GID를 호스트 사용자와 맞춤
# → 컨테이너가 만든 파일(storage, vendor)을 호스트에서 그대로 편집 가능
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data

COPY php.ini /usr/local/etc/php/conf.d/zz-custom.ini

WORKDIR /var/www
USER www-data
