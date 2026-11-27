# Stage 1: Cross-compile ultralight Windows System Tray client (.exe)
FROM golang:1.23-alpine AS client-builder
WORKDIR /src/client
COPY client/go.mod ./
COPY client/ ./
RUN mkdir -p /out \
    && GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build \
        -trimpath -ldflags="-s -w -H=windowsgui" \
        -o /out/pacefriend-client.exe ./cmd/pacefriend-client

# Stage 2: Build Vue 3 + Tailwind CSS v4 frontend bundle
FROM node:24-alpine AS frontend-builder
WORKDIR /build
COPY backend/package.json backend/package-lock.json* ./
RUN npm install --legacy-peer-deps
COPY backend/resources ./resources
COPY backend/public ./public
COPY backend/vite.config.js ./
RUN npm run build

# Stage 3: Production PHP 8.4-FPM + Nginx + Queue Worker
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    postgresql-client \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    ffmpeg \
    python3 \
    py3-pip \
    curl \
    bash \
    && pip3 install --no-cache-dir --break-system-packages edge-tts \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        zip \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install production Composer dependencies first for layer caching
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Copy application source, compiled Vite assets, and compiled Windows .exe
COPY backend/ .
COPY --from=frontend-builder /build/public/build ./public/build
RUN mkdir -p ./public/downloads
COPY --from=client-builder /out/pacefriend-client.exe ./public/downloads/pacefriend-client.exe

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Production configuration files
COPY backend/docker/php.ini /usr/local/etc/php/conf.d/99-pacefriend.ini
COPY backend/docker/nginx.conf /etc/nginx/http.d/default.conf
COPY backend/docker/supervisord.conf /etc/supervisord.conf
COPY backend/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
