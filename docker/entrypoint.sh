#!/bin/sh
set -e

if [ "$1" = "php-fpm" ] || [ "$1" = "php" ]; then
    # Production: refuse to start on development defaults (see App\Command\CheckProductionCommand).
    if [ "$APP_ENV" = "prod" ]; then
        php bin/console app:check-production
    fi

    if [ "$(id -u)" = "0" ]; then
        mkdir -p public/uploads config/jwt var
        chown -R www-data:www-data public/uploads
    fi

    if [ -f composer.json ] && [ ! -d vendor/symfony ]; then
        composer install --no-interaction --prefer-dist
    fi

    if [ "$1" = "php-fpm" ] && [ ! -f config/jwt/private.pem ] && [ -n "$JWT_PASSPHRASE" ]; then
        php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
        if [ "$(id -u)" = "0" ]; then
            chown www-data:www-data config/jwt/*.pem
            chmod 640 config/jwt/private.pem
        fi
    fi

    if [ "$1" = "php-fpm" ] && [ "$RUN_MIGRATIONS" = "1" ]; then
        until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
            echo "Waiting for database..."
            sleep 2
        done
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    fi
fi

exec "$@"
