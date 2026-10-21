#!/bin/sh
# Backups of the PostgreSQL database and the uploaded photos.
#   scripts/backup.sh                run a backup
#   scripts/backup.sh verify FILE    restore a dump into a scratch database and count rows (proves it is usable)
# Environment: COMPOSE (docker compose command), BACKUP_DIR (default ./backups), KEEP_DAYS (default 14).
set -eu
cd "$(dirname "$0")/.."

COMPOSE="${COMPOSE:-docker compose}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"

if [ "${1:-}" = "verify" ]; then
    FILE="${2:?usage: backup.sh verify <db-dump-file>}"
    SCRATCH="backup_verify_$$"
    echo "Restoring $FILE into scratch database $SCRATCH ..."
    $COMPOSE exec -T database createdb -U app "$SCRATCH"
    trap '$COMPOSE exec -T database dropdb -U app --if-exists "$SCRATCH" >/dev/null 2>&1 || true' EXIT
    $COMPOSE exec -T database pg_restore -U app -d "$SCRATCH" --no-owner --exit-on-error < "$FILE"
    for table in '"user"' dish review direct_message dish_collection; do
        printf '%-20s ' "$table"
        $COMPOSE exec -T database psql -U app -d "$SCRATCH" -Atc "SELECT COUNT(*) FROM $table"
    done
    echo "OK: the dump restores cleanly."
    exit 0
fi

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
DB_FILE="$BACKUP_DIR/db-$STAMP.dump"
UPLOADS_FILE="$BACKUP_DIR/uploads-$STAMP.tar.gz"

echo "Dumping the database -> $DB_FILE"
$COMPOSE exec -T database pg_dump -U app -Fc app > "$DB_FILE.part"
mv "$DB_FILE.part" "$DB_FILE"
[ -s "$DB_FILE" ] || { echo "Empty database dump: aborting." >&2; rm -f "$DB_FILE"; exit 1; }

echo "Archiving the uploads -> $UPLOADS_FILE"
$COMPOSE exec -T app tar czf - -C /var/www/app/public uploads > "$UPLOADS_FILE.part"
mv "$UPLOADS_FILE.part" "$UPLOADS_FILE"

( cd "$BACKUP_DIR" && sha256sum "$(basename "$DB_FILE")" "$(basename "$UPLOADS_FILE")" > "SHA256SUMS-$STAMP" )
chmod 600 "$DB_FILE" "$UPLOADS_FILE" 2>/dev/null || true

echo "Removing backups older than $KEEP_DAYS days"
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'uploads-*.tar.gz' -o -name 'SHA256SUMS-*' \) -mtime +"$KEEP_DAYS" -delete

echo "Done. Copy $BACKUP_DIR off the server too (a backup on the same disk is not a backup)."
