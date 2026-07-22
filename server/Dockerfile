FROM php:8.2-fpm-alpine

# System deps
RUN apk add --no-cache \
        git \
        unzip \
        curl \
        icu-libs \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-install \
        zip \
        mbstring \
        opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies first (cached layer)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy application source
COPY . .

# Create storage directories with correct permissions
RUN mkdir -p /storage/tokens \
             /storage/queues \
             /storage/schedules \
             /storage/settings \
             /storage/logs \
    && chown -R www-data:www-data /storage

# OPcache tuning for production
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

EXPOSE 9000

USER www-data
