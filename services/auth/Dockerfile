# Laravel service image. FrankenPHP = Caddy + PHP in one multi-threaded binary (no php-fpm/supervisor needed).
FROM dunglas/frankenphp:1-php8.4-alpine

RUN install-php-extensions pdo_mysql bcmath opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    TZ=Asia/Jakarta

WORKDIR /app

# Dependencies first for better layer caching.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/views storage/logs bootstrap/cache \
    && chmod +x docker/entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/app/docker/entrypoint.sh"]
