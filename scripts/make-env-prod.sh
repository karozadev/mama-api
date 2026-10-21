#!/bin/sh
# Creates .env.prod from .env.prod.example with random secrets. Never overwrites an existing file.
set -eu
cd "$(dirname "$0")/.."

if [ -f .env.prod ]; then
    echo ".env.prod already exists: not touching it." >&2
    exit 0
fi
command -v openssl >/dev/null || { echo "openssl is required" >&2; exit 1; }

rand() { openssl rand -hex "$1"; }

sed \
    -e "s|^APP_SECRET=.*|APP_SECRET=$(rand 32)|" \
    -e "s|^JWT_PASSPHRASE=.*|JWT_PASSPHRASE=$(rand 24)|" \
    -e "s|^MERCURE_JWT_SECRET=.*|MERCURE_JWT_SECRET=$(rand 32)|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(rand 24)|" \
    -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$(rand 24)|" \
    .env.prod.example > .env.prod
chmod 600 .env.prod
echo ".env.prod created (mode 600). Edit DOMAIN, LETSENCRYPT_EMAIL and CORS_ALLOW_ORIGIN, then keep a copy somewhere safe:"
echo "losing JWT_PASSPHRASE or the jwt volume logs every user out; losing DB_PASSWORD locks you out of the database."
