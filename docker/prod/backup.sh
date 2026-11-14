#!/bin/sh
# Backups of the database and of the uploaded files.
#
#   backup.sh                       back up now, then again every BACKUP_INTERVAL_HOURS (the service's default)
#   backup.sh once                  one backup now
#   backup.sh list                  what is there
#   backup.sh restore DB [FILES]    put a database dump back, and optionally a files archive
#
# Backups land in /backups: db-<time>.dump (pg_dump custom format) and
# files-<time>.tar.gz (uploads and the OAuth signing keys). Older than
# BACKUP_KEEP_DAYS they are removed. Copy /backups somewhere else as well:
# a backup on the same machine does not survive losing the machine.
set -eu

keep="${BACKUP_KEEP_DAYS:-14}"
every="${BACKUP_INTERVAL_HOURS:-24}"

backup() {
    stamp=$(date -u +%Y%m%d-%H%M%S)

    # Written under a temporary name first, so a half-written file is never taken for a backup.
    pg_dump --format=custom --file="/backups/db-$stamp.dump.part"
    mv "/backups/db-$stamp.dump.part" "/backups/db-$stamp.dump"

    # Caches, sessions and logs are not worth keeping.
    tar -czf "/backups/files-$stamp.tar.gz.part" --exclude=./framework --exclude=./logs -C /storage .
    mv "/backups/files-$stamp.tar.gz.part" "/backups/files-$stamp.tar.gz"

    find /backups -maxdepth 1 \( -name 'db-*.dump' -o -name 'files-*.tar.gz' \) -mtime "+$keep" -delete
    # Leftovers of a run that was interrupted.
    find /backups -maxdepth 1 -name '*.part' -mmin +60 -delete

    # The application's health report looks at this file.
    touch /backups/last-success
    echo "$(date -u +%FT%TZ) backup $stamp done: $(du -h "/backups/db-$stamp.dump" | cut -f1) database, $(du -h "/backups/files-$stamp.tar.gz" | cut -f1) files"
}

case "${1:-loop}" in
    once)
        backup
        ;;
    list)
        ls -lh /backups
        ;;
    restore)
        db="${2:?Give the database dump to restore, for example /backups/db-20260101-000000.dump}"
        echo "Restoring the database from $db. Everything in it now is replaced."
        pg_restore --clean --if-exists --no-owner --no-privileges --dbname="$PGDATABASE" "$db"

        if [ -n "${3:-}" ]; then
            echo "Restoring the files from $3."
            tar -xzf "$3" -C /storage
        fi
        echo "Done. Restart the application: docker compose restart app worker scheduler"
        ;;
    loop)
        while true; do
            backup || echo "$(date -u +%FT%TZ) backup FAILED" >&2
            sleep "$((every * 3600))"
        done
        ;;
    *)
        echo "Unknown command: $1" >&2
        exit 2
        ;;
esac
