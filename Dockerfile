# syntax=docker/dockerfile:1.7
#
# Imagen de producción de SAM (la de desarrollo es docker/8.5, vía Sail).
# Una sola imagen para web, horizon y scheduler: cambia sólo el `command`
# (ver compose.prod.yaml). Base serversideup/php: nginx + php-fpm sin root,
# escucha en 8080 y expone /healthcheck.
#
# Las VITE_* se hornean en el bundle del frontend al construir: el cliente de
# websockets (resources/js/echo.ts) sólo conoce el host público de Soketi por
# estas variables, así que van como build args y no como env de runtime.

ARG PHP_IMAGE=serversideup/php:8.5-fpm-nginx-debian-v4.5.1
ARG NODE_IMAGE=node:22-trixie-slim

FROM ${NODE_IMAGE} AS node

# --- base: extensiones y binarios que el runtime necesita -------------------
FROM ${PHP_IMAGE} AS base

USER root

# gd (dompdf / phpspreadsheet), intl, bcmath, exif. ffmpeg: ExtractVideoFramesJob
# saca fotogramas de los clips de Samsara (config/media-frames.php).
RUN install-php-extensions gd intl bcmath exif \
    && apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

USER www-data
WORKDIR /var/www/html

# --- vendor: dependencias PHP de producción ---------------------------------
FROM base AS vendor

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
    --no-scripts --no-autoloader

# --- assets: bundle de Vite (Wayfinder necesita PHP + la app para generar) ---
FROM base AS assets

USER root
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
USER www-data

COPY --chown=www-data:www-data package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction

ARG VITE_APP_NAME=SAM
ARG VITE_PUSHER_APP_KEY
ARG VITE_PUSHER_HOST
ARG VITE_PUSHER_PORT=443
ARG VITE_PUSHER_SCHEME=https
ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_PUSHER_APP_KEY=${VITE_PUSHER_APP_KEY} \
    VITE_PUSHER_HOST=${VITE_PUSHER_HOST} \
    VITE_PUSHER_PORT=${VITE_PUSHER_PORT} \
    VITE_PUSHER_SCHEME=${VITE_PUSHER_SCHEME}

RUN npm run build

# --- app: imagen final ------------------------------------------------------
FROM base AS app

# Buffers de FastCGI: con los 8k por defecto nginx corta las respuestas de
# Inertia (cabeceras de sesión, CSP, Link) con un 502 "too big header".
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_MEMORY_LIMIT=512M \
    NGINX_FASTCGI_BUFFER_SIZE=32k \
    NGINX_FASTCGI_BUFFERS="16 16k"

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build

RUN mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction

ARG GIT_SHA=unknown
ENV APP_VERSION=${GIT_SHA}
LABEL org.opencontainers.image.source="https://github.com/vicbravodev/sam-global-systems" \
      org.opencontainers.image.revision="${GIT_SHA}"
