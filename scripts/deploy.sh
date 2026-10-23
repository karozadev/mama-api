#!/bin/sh
# Runs ON the server (called by .github/workflows/deploy.yml): pulls the images for IMAGE_TAG,
# migrates the database, restarts the stack and rolls the code back if it does not become healthy.
#   IMAGE_TAG=<git sha> scripts/deploy.sh
set -eu
cd "$(dirname "$0")/.."
: "${IMAGE_TAG:?IMAGE_TAG is required}"

# First install: create .env.prod (random secrets, generated here and never printed).
if [ ! -f .env.prod ]; then
    : "${DOMAIN:?DOMAIN is required for the first install}"
    ./scripts/make-env-prod.sh
    domain_re=$(printf '%s' "$DOMAIN" | sed 's/\./\\./g')
    sed -i -e '/^DOMAIN=/d' -e '/^CORS_ALLOW_ORIGIN=/d' .env.prod
    {
        printf 'DOMAIN=%s\n' "$DOMAIN"
        printf 'CORS_ALLOW_ORIGIN=^https://%s$\n' "$domain_re"
    } >> .env.prod
    echo "Created .env.prod on the server: back it up (it holds the secrets, see docs/DEPLOY.md)."
fi

# Older .env.prod files (standalone TLS edge) asked for port 80: the stack now sits behind a reverse proxy.
if [ "$(sed -n 's/^HTTP_PORT=//p' .env.prod | tail -n 1)" = "80" ]; then
    sed -i 's/^HTTP_PORT=.*/HTTP_PORT=8090/' .env.prod
fi
grep -q '^HTTP_PORT=' .env.prod || printf 'HTTP_PORT=8090\n' >> .env.prod

PROD="docker compose -f compose.prod.yaml --env-file .env.prod"
previous=$(sed -n 's/^IMAGE_TAG=//p' .env.prod | tail -n 1)

set_tag() {
    if grep -q '^IMAGE_TAG=' .env.prod; then
        sed -i "s|^IMAGE_TAG=.*|IMAGE_TAG=$1|" .env.prod
    else
        printf 'IMAGE_TAG=%s\n' "$1" >> .env.prod
    fi
}

set_tag "$IMAGE_TAG"
$PROD pull app nginx

# Migrations run before the restart (see docs/DEPLOY.md: back up before risky ones).
$PROD up -d --wait database redis
$PROD run --rm migrate

if ! $PROD up -d --remove-orphans --wait --wait-timeout 180; then
    echo "Deployment of $IMAGE_TAG failed; logs follow." >&2
    $PROD ps >&2 || true
    $PROD logs --tail=60 app nginx >&2 || true
    if [ -n "$previous" ] && [ "$previous" != "$IMAGE_TAG" ]; then
        echo "Rolling back the code to $previous (the database is NOT rolled back)." >&2
        set_tag "$previous"
        $PROD up -d --remove-orphans || true
    fi
    exit 1
fi

$PROD ps
docker image prune -f >/dev/null
port=$(sed -n 's/^HTTP_PORT=//p' .env.prod | tail -n 1)
echo "Deployed $IMAGE_TAG (previous: ${previous:-none}). Serving HTTP on 127.0.0.1:${port:-8090}; point the reverse proxy for the domain there."
