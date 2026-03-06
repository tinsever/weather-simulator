FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

FROM php:8.3-fpm AS app
WORKDIR /app

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libsqlite3-dev gosu; \
    docker-php-ext-install pdo_sqlite opcache; \
    rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

RUN mkdir -p /app/database && chown -R www-data:www-data /app

ENV APP_DEBUG=false
ENV APP_TIMEZONE=Europe/Vaduz
ENV DB_PATH=/app/database/clauswetter.db

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

FROM nginx:1.27-alpine AS web
WORKDIR /app

COPY . /app
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
