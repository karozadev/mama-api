# syntax=docker/dockerfile:1
FROM composer:2 AS composer_bin

FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache icu-libs libpq libzip libpng libjpeg-turbo libwebp bash git unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache zip gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/app
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---- development ----
FROM base AS dev
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps
ENV APP_ENV=dev

# ---- production ----
FROM base AS prod
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
ENV APP_ENV=prod APP_DEBUG=0
COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/php/fpm-prod.conf /usr/local/etc/php-fpm.d/zz-prod.conf
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress
COPY . .
# No "composer dump-env": the container must get its configuration from real environment variables
# (app:check-production refuses to start on the development defaults of .env).
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && APP_SECRET=build-time-only php bin/console cache:warmup \
    && mkdir -p var/log public/uploads config/jwt \
    && chown -R www-data:www-data var public/uploads config/jwt \
    && rm -f /usr/bin/composer
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD php-fpm -t 2>/dev/null || exit 1

# ---- nginx for production (TLS, rate limits, static files) ----
FROM nginx:1.27-alpine AS nginx-prod
COPY docker/nginx/prod.conf.template /etc/nginx/templates/default.conf.template
COPY --from=prod /var/www/app/public /var/www/app/public
