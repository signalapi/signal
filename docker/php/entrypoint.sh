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

# Dev secrets: generate once into .env.local (gitignored) when nothing provides
# them — a committed default would mean every install shares the same key.
if [ -z "$SKIP_DB_INIT" ] && [ -z "$APP_SECRET_KEY" ] && ! grep -qs "^APP_SECRET_KEY=" .env.local; then
    echo "[entrypoint] Generating local secrets into .env.local..."
    {
        echo "APP_SECRET=$(php -r 'echo bin2hex(random_bytes(16));')"
        echo "APP_SECRET_KEY=$(php -r 'echo bin2hex(random_bytes(32));')"
    } >> .env.local
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
