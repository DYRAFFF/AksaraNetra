# Dockerfile untuk backend PHP/Laravel AksaraNetra (Phase 6).
#
# PENTING: build context harus REPO ROOT, bukan folder php-backend/.
# Alasan: bridge/audit-cli.mjs mengimpor langsung dari ../../engine/src/...
#
# Build lokal (dari repo root):
#   docker build -f php-backend/Dockerfile -t aksaranetra-php-backend .
#
# Menjalankan dengan docker compose:
#   docker compose -f php-backend/docker-compose.yml up --build

FROM php:8.3-fpm-bookworm

# 1. Install system tools, libraries, nginx, supervisor
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    git \
    nginx \
    supervisor \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# 2. Install PHP extensions
# (pdo_sqlite, mbstring, openssl, json, curl are already built-in on php:8.3-fpm-bookworm)
# Add pcntl (for worker CLI signal handling) and opcache (for production performance)
RUN docker-php-ext-install pcntl opcache

# 3. Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# 4. Install Node.js 22 (for engine execution via bridge)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# 5. Install engine dependencies (Playwright + axe-core)
COPY engine/package.json engine/package-lock.json ./engine/
RUN cd engine && npm ci --omit=dev

# 6. Install Playwright Chromium + system dependencies
# (same approach and env vars as backend/Dockerfile)
ENV PLAYWRIGHT_BROWSERS_PATH=/app/.playwright
RUN cd engine && npx playwright install --with-deps chromium

# 7. Install PHP dependencies via Composer (no-dev, optimized)
COPY php-backend/composer.json php-backend/composer.lock ./php-backend/
RUN cd php-backend && composer install --no-dev --no-interaction --no-scripts --optimize-autoloader

# 8. Copy source files (only what is needed: engine/src and php-backend)
COPY engine/src ./engine/src
COPY php-backend/app ./php-backend/app
COPY php-backend/bootstrap ./php-backend/bootstrap
COPY php-backend/bridge ./php-backend/bridge
COPY php-backend/config ./php-backend/config
COPY php-backend/database ./php-backend/database
COPY php-backend/docker ./php-backend/docker
COPY php-backend/public ./php-backend/public
COPY php-backend/routes ./php-backend/routes
COPY php-backend/artisan ./php-backend/artisan

# 9. Dump optimized autoloader for production
RUN cd php-backend && composer dump-autoload --optimize --no-dev

# 10. Create non-root user (matching UID 1000 standard)
RUN groupadd -g 1000 aksara && useradd -u 1000 -g aksara -m -s /bin/bash aksara

# 11. Prepare /data volume and app directories with proper non-root permissions
ENV BACKEND_DATA_DIR=/data
RUN mkdir -p /data \
    /app/php-backend/storage/framework/cache/data \
    /app/php-backend/storage/framework/sessions \
    /app/php-backend/storage/framework/views \
    /app/php-backend/storage/logs \
    /app/php-backend/bootstrap/cache \
    && chown -R aksara:aksara /data /app \
    && chmod +x /app/php-backend/docker/entrypoint.sh

# 12. Production environment variables
ENV NODE_ENV=production
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV HOST=0.0.0.0
ENV PORT=8787
ENV NODE_OPTIONS=--max-old-space-size=192
ENV CHROMIUM_LOW_RESOURCE_MODE=1

EXPOSE 8787

USER aksara

ENTRYPOINT ["/app/php-backend/docker/entrypoint.sh"]

CMD ["api"]
