#!/bin/sh
# Runs ON the server (called by .github/workflows/deploy.yml): pulls the images for IMAGE_TAG,
# migrates the database, restarts the stack and rolls the code back if it does not become healthy.
#   IMAGE_TAG=<git sha> scripts/deploy.sh
set -eu
cd "$(dirname "$0")/.."
: "${IMAGE_TAG:?IMAGE_TAG is required}"

if [ ! -f .env.prod ]; then
    echo "No .env.prod on this server. First time? Run: make secrets, edit .env.prod, make cert, then deploy again." >&2
    exit 1
fi

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
echo "Deployed $IMAGE_TAG (previous: ${previous:-none})."
