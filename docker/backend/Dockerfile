# Local dev runtime for backend/laravel: PHP 8.3 + pgsql + pcntl (Reverb) + ffmpeg (video concat).
FROM php:8.3-cli-bookworm

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pcntl sockets zip intl bcmath

RUN printf "upload_max_filesize=200M\npost_max_size=200M\nmemory_limit=512M\n" > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www
