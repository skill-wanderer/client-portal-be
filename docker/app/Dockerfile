FROM php:8.4-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install mbstring pdo_sqlite zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

COPY --chown=www-data:www-data . .
COPY --chmod=0755 docker/app/entrypoint.sh /usr/local/bin/backend-entrypoint

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p \
        storage/app \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache database vendor

USER www-data

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/backend-entrypoint"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "docker/app/router.php"]
