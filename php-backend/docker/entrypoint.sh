#!/bin/sh
set -e

# Change directory to Laravel app
cd /app/php-backend

# Ensure data directory exists
if [ -n "$BACKEND_DATA_DIR" ]; then
    mkdir -p "$BACKEND_DATA_DIR" 2>/dev/null || true
fi

# Ensure temporary and runtime storage directories exist
mkdir -p /tmp/client_temp /tmp/proxy_temp /tmp/fastcgi_temp /tmp/uwsgi_temp /tmp/scgi_temp 2>/dev/null || true
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache 2>/dev/null || true

if [ "$1" = "api" ]; then
    echo "==> [API] Running database migrations..."
    php artisan migrate --force

    echo "==> [API] Starting API services (Nginx + PHP-FPM via Supervisord)..."
    exec supervisord -c /app/php-backend/docker/supervisord.conf
elif [ "$1" = "worker" ]; then
    echo "==> [Worker] Starting audit worker..."
    exec php artisan audit:work
else
    exec "$@"
fi
