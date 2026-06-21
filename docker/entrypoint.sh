#!/bin/sh
#
# Container entrypoint with self-healing database migration.
#
# When RUN_MIGRATIONS_ON_START is not "0", the container runs `app:migrate`
# before serving traffic. That command:
#   * waits on a PostgreSQL advisory lock, so concurrent replicas serialise and
#     a killed pod never leaves a stale lock behind;
#   * runs migrations all-or-nothing, so an interruption rolls back cleanly;
#   * is idempotent — replicas that find nothing pending exit successfully.
#
# The retry loop below keeps trying until it succeeds, which also handles the
# common "database not ready yet" race on a cold start of the whole stack.
#
# In Kubernetes you typically run `app:migrate` in an initContainer and set
# RUN_MIGRATIONS_ON_START=0 on the main container; the logic is identical.
set -e

if [ "${RUN_MIGRATIONS_ON_START:-1}" != "0" ]; then
    echo "[entrypoint] Ensuring the database schema is up to date..."
    attempt=1
    until php bin/console app:migrate --no-interaction; do
        echo "[entrypoint] Migration not complete (attempt ${attempt}); retrying in 5s..."
        attempt=$((attempt + 1))
        sleep 5
    done
    echo "[entrypoint] Database is ready."
fi

# Warm the prod cache (no-op in dev). Never fatal.
if [ "${APP_ENV:-prod}" = "prod" ]; then
    php bin/console cache:warmup --no-interaction || true
fi

# Publish the compiled public/ directory (incl. hashed assets) to a shared
# volume so a separate nginx container can serve static files. Done on every
# start so a new image always ships fresh assets to the web tier.
if [ -n "${PUBLIC_SYNC_DIR:-}" ] && [ -d /app/public ]; then
    echo "[entrypoint] Publishing public/ to ${PUBLIC_SYNC_DIR}..."
    cp -a /app/public/. "${PUBLIC_SYNC_DIR}/" || echo "[entrypoint] WARNING: could not publish public/."
fi

# Ensure runtime dirs remain writable by the php-fpm worker user.
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data var 2>/dev/null || true
fi

exec "$@"
