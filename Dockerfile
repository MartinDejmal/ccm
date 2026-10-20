# syntax=docker/dockerfile:1
FROM node:24-bookworm-slim@sha256:a5f4366e834695d5f363b920c104953a535d3a9864d1bdf74566a0ebe01daee5 AS frontend
WORKDIR /app
COPY package*.json ./
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/proxy_ca; fi; npm ci
COPY resources resources
COPY vite.config.js tsconfig.json ./
RUN npm run build

FROM dunglas/frankenphp:1-php8.4@sha256:b32a81ad4db40b528ada04087f47e22833f260266e73b3d88c62fd54e2a29aa6 AS runtime-base
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then export CURL_CA_BUNDLE=/run/secrets/proxy_ca SSL_CERT_FILE=/run/secrets/proxy_ca; fi; \
    install-php-extensions pdo_mysql intl zip opcache
COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
WORKDIR /app

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS dependencies
WORKDIR /app
ARG COMPOSER_DOWNLOAD_MODE=dist
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then export COMPOSER_CAFILE=/run/secrets/proxy_ca GIT_SSL_CAINFO=/run/secrets/proxy_ca; fi; \
    composer install --no-dev --prefer-${COMPOSER_DOWNLOAD_MODE} --no-interaction --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

FROM runtime-base AS production
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr SERVER_NAME=:8080 XDG_CONFIG_HOME=/tmp/config XDG_DATA_HOME=/tmp/data
COPY --from=dependencies --chown=www-data:www-data /app /app
COPY --from=frontend --chown=www-data:www-data /app/public/build /app/public/build
COPY --chmod=644 deploy/Caddyfile /etc/frankenphp/Caddyfile
RUN composer check-platform-reqs --no-dev && php artisan package:discover --ansi
RUN mkdir -p storage/app/private storage/framework/sessions storage/framework/views storage/framework/cache bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && setcap -r /usr/local/bin/frankenphp
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=4 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/ready") === false ? 1 : 0);'
