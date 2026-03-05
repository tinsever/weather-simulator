FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

FROM php:8.3-cli
WORKDIR /app

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libsqlite3-dev gosu; \
    docker-php-ext-install pdo_sqlite; \
    rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN mkdir -p /app/database && chown -R www-data:www-data /app

ENV APP_DEBUG=false
ENV APP_TIMEZONE=Europe/Vaduz
ENV DB_PATH=/app/database/clauswetter.db

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
