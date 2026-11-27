# Stage 1: Build Vue 3 + Tailwind CSS v4 frontend bundle
FROM node:24-alpine AS frontend-builder
WORKDIR /build
COPY package.json package-lock.json* ./
RUN npm install --legacy-peer-deps
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

# Stage 2: Production PHP 8.4-FPM + Nginx + Queue Worker
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    postgresql-client \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    curl \
    bash \
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
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Copy application source and compiled Vite assets
COPY . .
COPY --from=frontend-builder /build/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Production configuration files
COPY docker/php.ini /usr/local/etc/php/conf.d/99-pacefriend.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
