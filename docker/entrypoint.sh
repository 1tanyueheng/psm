#!/bin/sh
# =====================================================================
# Container entrypoint
# =====================================================================
# Runs the boot steps that must happen once per container start, in the
# order that avoids the classic first-deploy failures: a missing APP_KEY,
# an unlinked storage dir, or a stale config cache hiding real env vars.
# =====================================================================

set -e

echo "[entrypoint] PSM Management System — starting"

# ---------------------------------------------------------------------
# 1. Application key
# ---------------------------------------------------------------------
# Generated once and persisted. Regenerating on every boot would silently
# invalidate every session and encrypted value.
if [ -z "$APP_KEY" ]; then
    if [ -f /var/www/html/.env ] && grep -q "^APP_KEY=base64:" /var/www/html/.env; then
        echo "[entrypoint] APP_KEY present in .env"
    else
        echo "[entrypoint] WARNING: APP_KEY is not set."
        echo "[entrypoint] Set it in the host environment, e.g.:"
        echo "[entrypoint]   php artisan key:generate --show"
        echo "[entrypoint] Continuing — the app will fail on any encrypted value."
    fi
fi

# ---------------------------------------------------------------------
# 2. Storage link
# ---------------------------------------------------------------------
# Without this, submission downloads and leaderboard posters 404.
if [ ! -L /var/www/html/public/storage ]; then
    echo "[entrypoint] Creating storage symlink"
    php /var/www/html/artisan storage:link --no-interaction || true
fi

# ---------------------------------------------------------------------
# 3. Writable paths
# ---------------------------------------------------------------------
# A mounted volume arrives owned by root, so permissions are re-applied
# here rather than only at build time.
#
# Both commands are tolerant of failure on purpose. This script runs under
# `set -e`, so an unguarded failure here aborts the container before
# supervisord ever starts — which presents as an endless restart loop rather
# than a readable error.
#
# The realistic failure is a Windows bind mount: NTFS has no Unix ownership,
# so `chown` cannot succeed. That is harmless in local development, because
# Docker Desktop maps ownership itself. On Linux the commands do real work and
# still run normally.
mkdir -p \
    /var/www/html/storage/app/public \
    /var/www/html/storage/app/submissions \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null \
    || echo "[entrypoint] chown skipped (expected on a Windows bind mount)"

chmod -R ug+rwx /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------
# 4. Config cache
# ---------------------------------------------------------------------
# Cached here (not at build time) so runtime environment variables are the
# ones that take effect. A build-time cache is the single most common
# cause of "my env var is set but the app ignores it" on Render.
echo "[entrypoint] Caching configuration"
php /var/www/html/artisan config:clear --no-interaction >/dev/null 2>&1 || true

if [ -n "$APP_KEY" ]; then
    php /var/www/html/artisan config:cache --no-interaction || true
    php /var/www/html/artisan route:cache  --no-interaction || true
    php /var/www/html/artisan view:cache   --no-interaction || true
else
    echo "[entrypoint] Skipping config cache (no APP_KEY)"
fi

# ---------------------------------------------------------------------
# 5. Migrations
# ---------------------------------------------------------------------
# Opt-in via RUN_MIGRATIONS=true. Migrating automatically on every boot is
# convenient but dangerous: a bad deploy takes the database with it. The
# intended production flow is a one-off release command instead.
#
# Deliberately non-fatal. Under `set -e` an unguarded failure here exits the
# container, and Docker restarts it — so a single bad migration presents as an
# endless restart loop with the actual SQL error buried in the logs. Letting
# the container come up instead means the error is visible and the migration
# can be re-run by hand.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "[entrypoint] Running migrations"
    if ! php /var/www/html/artisan migrate --force --no-interaction; then
        echo "[entrypoint] ----------------------------------------------------"
        echo "[entrypoint] MIGRATION FAILED. The container will still start so"
        echo "[entrypoint] the error above stays readable. Re-run it with:"
        echo "[entrypoint]   docker compose exec app php artisan migrate --force"
        echo "[entrypoint] ----------------------------------------------------"
    fi

    if [ "$RUN_SEED" = "true" ]; then
        echo "[entrypoint] Seeding (demo data — never enable this in production)"
        php /var/www/html/artisan db:seed --force --no-interaction || \
            echo "[entrypoint] Seeding failed — see the error above."
    fi
fi

# ---------------------------------------------------------------------
# 6. Hand off
# ---------------------------------------------------------------------
echo "[entrypoint] Ready — handing off to supervisord"
exec "$@"
