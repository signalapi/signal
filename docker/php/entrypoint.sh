#!/usr/bin/env bash
set -e

cd /var/www

# Install dependencies if vendor is missing (first boot / fresh clone).
# All containers share one mounted project dir, so only the primary installs —
# two composer runs over the same vendor/ leave it half-written.
if [ -z "$SKIP_DB_INIT" ]; then
    if [ ! -f vendor/autoload_runtime.php ]; then
        echo "[entrypoint] Installing composer dependencies..."
        composer install --no-interaction --prefer-dist
    fi
else
    echo "[entrypoint] Waiting for vendor/ from the primary container..."
    until [ -f vendor/autoload_runtime.php ]; do sleep 2; done
fi

# Wait for the database to accept connections
echo "[entrypoint] Waiting for database..."
until php -r 'exit(@fsockopen("database", 5432) ? 0 : 1);' 2>/dev/null; do
    sleep 1
done

# DB schema init runs only on the primary container (the worker skips it to avoid
# racing the migration on first boot).
if [ -z "$SKIP_DB_INIT" ]; then
    echo "[entrypoint] Preparing database..."
    php bin/console doctrine:database:create --if-not-exists --no-interaction || true
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

    echo "[entrypoint] Ensuring super admin exists..."
    php bin/console app:create-superadmin || true

    echo "[entrypoint] Warming cache..."
    php bin/console cache:clear --no-interaction || true
else
    echo "[entrypoint] Worker: waiting for migrations from primary..."
    sleep 6
fi

exec "$@"
