#!/bin/sh
set -e

cd /var/www/html

# The project is bind-mounted at runtime, which shadows the image's vendor/.
if [ ! -d vendor ]; then
    echo "[entrypoint] installing dependencies..."
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    echo "[entrypoint] creating .env from .env.example..."
    cp .env.example .env
    php artisan key:generate --force
fi

exec "$@"
