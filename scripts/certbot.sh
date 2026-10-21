#!/bin/sh
# Let's Encrypt certificates for DOMAIN (from .env.prod).
#   scripts/certbot.sh issue   first certificate (needs port 80 free: stop the stack or run before the first start)
#   scripts/certbot.sh renew   renewal through the running nginx, then reload it (put it in a daily cron)
set -eu
cd "$(dirname "$0")/.."
. ./.env.prod
LE_DIR="${LETSENCRYPT_DIR:-/etc/letsencrypt}"
PROD="docker compose -f compose.prod.yaml --env-file .env.prod"

case "${1:-}" in
    issue)
        docker run --rm -p 80:80 -v "$LE_DIR:/etc/letsencrypt" certbot/certbot certonly \
            --standalone -d "$DOMAIN" -m "$LETSENCRYPT_EMAIL" --agree-tos --non-interactive
        ;;
    renew)
        docker run --rm -v "$LE_DIR:/etc/letsencrypt" -v rasoherina-prod_certbot_www:/var/www/certbot certbot/certbot renew \
            --webroot -w /var/www/certbot --non-interactive
        $PROD exec -T nginx nginx -s reload
        ;;
    *)
        echo "usage: $0 issue|renew" >&2
        exit 2
        ;;
esac
