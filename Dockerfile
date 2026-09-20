# =====================================================================
# PSM Management System — application image
# =====================================================================
# Multi-stage build: Composer and npm run in throwaway stages so the runtime
# image carries no build toolchain. This matters on Render, where image size
# directly affects cold-start time.
# =====================================================================

# ---------------------------------------------------------------------
# Stage 1 — PHP dependencies
# ---------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Copy manifests first so the dependency layer is cached and only rebuilt
# when composer.json or composer.lock actually change.
#
# composer.lock is not committed at scaffold time. COPY with a glob tolerates
# its absence, and the install below falls back to resolving from
# composer.json. Once `composer update` has been run once, the lock file will
# exist and `composer install` will pin exact versions from then on.
COPY backend/composer.json backend/composer.lock* ./

RUN if [ -f composer.lock ]; then \
        composer install --no-dev --no-interaction --no-scripts \
            --no-autoloader --prefer-dist; \
    else \
        echo "No composer.lock — resolving dependencies from composer.json"; \
        composer update --no-dev --no-interaction --no-scripts \
            --no-autoloader --prefer-dist; \
    fi

# Now bring in the real source and finish autoloading
COPY backend/ ./

RUN mkdir -p bootstrap/cache storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------------
# Stage 2 — frontend assets
# ---------------------------------------------------------------------
FROM node:20-alpine AS assets

WORKDIR /app

# Same reasoning as stage 1: the lock file is not committed in the scaffold,
# so the glob tolerates its absence. The branch is explicit rather than
# `npm ci || npm install`, because a *broken* lock file should fail the build
# loudly instead of being silently ignored — `npm ci` refuses a lock that
# disagrees with the manifest, and that refusal is information worth keeping.
#
# A lock file that exists but carries no resolved dependencies is worse than
# none at all: `npm ci` would accept it and install nothing. The grep below
# requires at least one real package entry before choosing that path.
COPY frontend/package.json frontend/package-lock.json* ./

RUN if [ -f package-lock.json ] \
        && grep -q '"node_modules/' package-lock.json; then \
        echo "Using committed package-lock.json"; \
        npm ci --no-audit --no-fund; \
    else \
        echo "No usable package-lock.json - resolving ranges from package.json"; \
        rm -f package-lock.json; \
        npm install --no-audit --no-fund; \
    fi

COPY frontend/ ./

# vite.config.js sets `outDir: '../backend/public/build'`, which is right for
# a developer running the build from `frontend/` against a checkout. Here the
# layout differs, so the output is overridden to a path inside this stage and
# copied explicitly in stage 3. Relying on the config's relative path from
# /app would write to /backend/public/build and the COPY below would find
# nothing.
RUN npx vite build --outDir dist --emptyOutDir

# ---------------------------------------------------------------------
# Stage 3 — runtime
# ---------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS runtime

# Build deps are removed in the same layer to keep the image small.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
    && apk add --no-cache \
        libpng \
        libjpeg-turbo \
        freetype \
        libzip \
        icu \
        oniguruma \
        mysql-client \
        nginx \
        supervisor \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        intl \
        mbstring \
        bcmath \
        opcache \
        pcntl \
    && apk del .build-deps

# Recommended production PHP settings
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-psm.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# Application code
COPY backend/ ./

# Vendor from stage 1, built assets from stage 2
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/dist ./public/build

# nginx config for this image
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/psm.conf

# Writable paths. In production, mount a persistent disk over storage/app so
# uploaded submissions survive a redeploy — containers are ephemeral.
RUN mkdir -p \
        storage/app/public \
        storage/app/submissions \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

# The health endpoint is registered in bootstrap/app.php as /up
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/psm.conf"]
