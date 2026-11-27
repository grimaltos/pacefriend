#!/usr/bin/env bash
set -e

cd /var/www/html

# Ensure writable runtime directories exist inside mounted storage volume
mkdir -p \
    storage/app/public \
    storage/app/private/audio-cache \
    storage/app/audio-cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [ "${DB_CONNECTION}" = "pgsql" ]; then
    echo "[PaceFriend] Esperando a PostgreSQL en ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
    until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-pacefriend}" >/dev/null 2>&1; do
        sleep 1
    done
fi

echo "[PaceFriend] Ejecutando migraciones y sincronizando catálogo de circuitos..."
php artisan migrate --force --no-interaction
php artisan tracks:sync --no-interaction
php artisan db:seed --force --no-interaction

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[PaceFriend] Contenedor listo. Iniciando PHP-FPM, Nginx y Queue Worker..."
exec "$@"
