#!/bin/sh
# Restores a backup made by backup.sh. DESTRUCTIVE: the target data is replaced.
#   scripts/restore.sh backups/db-….dump            replaces the database
#   scripts/restore.sh backups/uploads-….tar.gz     replaces the uploaded photos
# Environment: COMPOSE (docker compose command). Set ASSUME_YES=1 to skip the confirmation.
set -eu
cd "$(dirname "$0")/.."
COMPOSE="${COMPOSE:-docker compose}"
FILE="${1:?usage: restore.sh <db-….dump | uploads-….tar.gz>}"
[ -f "$FILE" ] || { echo "No such file: $FILE" >&2; exit 1; }

confirm() {
    [ "${ASSUME_YES:-0}" = "1" ] && return 0
    printf '%s Type "yes" to continue: ' "$1"
    read -r answer
    [ "$answer" = "yes" ]
}

case "$FILE" in
    *.dump)
        confirm "This REPLACES the whole database with $FILE." || { echo "Aborted."; exit 1; }
        $COMPOSE stop app worker >/dev/null 2>&1 || true
        $COMPOSE exec -T database psql -U app -d postgres -c "DROP DATABASE IF EXISTS app WITH (FORCE)"
        $COMPOSE exec -T database psql -U app -d postgres -c "CREATE DATABASE app OWNER app"
        $COMPOSE exec -T database pg_restore -U app -d app --no-owner --exit-on-error < "$FILE"
        $COMPOSE start app worker >/dev/null 2>&1 || true
        echo "Database restored."
        ;;
    *.tar.gz)
        confirm "This REPLACES the uploaded photos with $FILE." || { echo "Aborted."; exit 1; }
        $COMPOSE exec -T app sh -c 'rm -rf /var/www/app/public/uploads/* && tar xzf - -C /var/www/app/public' < "$FILE"
        $COMPOSE exec -T app chown -R www-data:www-data /var/www/app/public/uploads
        echo "Uploads restored."
        ;;
    *)
        echo "Unknown backup type: $FILE" >&2
        exit 2
        ;;
esac
