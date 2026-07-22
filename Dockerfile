# syntax=docker/dockerfile:1
#
# Critter 2.0 - multi-stage image.
#
#   target=prod  → optimized PHP-FPM image (no dev deps, compiled assets,
#                  opcache tuned). Paired with the bundled nginx config.
#   target=dev   → PHP-FPM image with the full toolchain (composer dev deps,
#                  git, phpunit, linters) intended to bind-mount the source so
#                  developers can edit and test live.
#
# Build:
#   docker build --target prod -t critter:prod .
#   docker build --target dev  -t critter:dev  .

############################  base  ############################
FROM php:8.4-fpm-alpine AS base

# PHP extensions via the well-maintained installer (handles build deps cleanly).
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions \
        pdo_pgsql \
        intl \
        zip \
        opcache \
        gd \
        @composer

# Runtime utilities used by the entrypoint (postgres client for readiness, bash).
RUN apk add --no-cache bash postgresql-client fcgi

WORKDIR /app

# Production-leaning PHP defaults (opcache on). The dev stage relaxes these.
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/zz-app.ini

# Entrypoint: optional self-healing auto-migrate, then exec the CMD.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

############################  vendor (prod deps only)  ############################
FROM base AS vendor
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
# Install production dependencies without running scripts (no app code yet).
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-interaction

############################  prod  ############################
FROM base AS prod
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    RUN_MIGRATIONS_ON_START=1

# Deployed version string (git tag or short commit hash), supplied by CI. Baked
# into a VERSION file that App\Service\AppVersion reads for display (e.g. /home).
ARG APP_VERSION=dev

# Vendor first (better layer caching), then the application source.
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN printf '%s' "${APP_VERSION}" > VERSION

# Finalize the autoloader and compile front-end assets (AssetMapper). A throwaway
# APP_SECRET keeps the prod kernel bootable at build time; the real secret is
# injected at runtime. Asset compilation does not touch the database.
RUN composer dump-autoload --classmap-authoritative --no-dev \
 && APP_SECRET=build-time-placeholder php bin/console importmap:install \
 && APP_SECRET=build-time-placeholder php bin/console asset-map:compile \
 && mkdir -p var \
 && chown -R www-data:www-data var public/assets \
 && rm -rf var/cache/dev

# Note: we deliberately keep the default php-fpm process model - the master
# (and our entrypoint) run as root so the entrypoint can migrate and publish
# assets to the shared volume, while php-fpm *worker* processes drop to
# www-data. Application code is never executed as root.
EXPOSE 9000

############################  dev  ############################
FROM base AS dev
ENV APP_ENV=dev \
    APP_DEBUG=1 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    RUN_MIGRATIONS_ON_START=0

# Developer tooling: git for composer VCS, plus the dev PHP profile (opcache
# revalidation, higher limits). phpunit & linters arrive via composer dev deps.
RUN apk add --no-cache git make \
 && rm -f /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-dev.ini /usr/local/etc/php/conf.d/zz-app.ini

# Source is expected to be bind-mounted at runtime; this COPY just makes the
# image self-contained if run without a mount.
COPY . .
RUN composer install --prefer-dist --no-progress --no-interaction || true

EXPOSE 9000
