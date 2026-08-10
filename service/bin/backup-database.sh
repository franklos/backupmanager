#!/bin/bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/backup-common.sh"
load_config

NC_CONFIG="${NC_PATH%/}/config/config.php"

if [ ! -r "$NC_CONFIG" ]; then
    echo "Nextcloud config niet leesbaar: $NC_CONFIG" >&2
    exit 2
fi

mapfile -t NC_DB_CONFIG < <(
    php -r '
        include $argv[1];
        foreach (["dbhost", "dbname", "dbuser", "dbpassword"] as $key) {
            echo (string)($CONFIG[$key] ?? ""), PHP_EOL;
        }
    ' "$NC_CONFIG"
)

DB_HOST="${NC_DB_CONFIG[0]:-${DB_HOST:-localhost}}"
DB_NAME="${NC_DB_CONFIG[1]:-${DB_NAME:-}}"
DB_USER="${NC_DB_CONFIG[2]:-${DB_USER:-}}"
DB_PASSWORD="${NC_DB_CONFIG[3]:-}"

DATE=$(TZ="$TIMEZONE" date '+%Y-%m-%d_%H-%M')
DISPLAY_DATE="$(now)"

DB_DUMP_DIR="${DB_DUMP_DIR:-/var/lib/backupmanager/database}"
STATUS_FILE="$STATUS_DIR/db-status.txt"
HISTORY_FILE="$STATUS_DIR/db-history.txt"
DUMP_FILE="$DB_DUMP_DIR/nextcloud-db-$DATE.sql.gz"

mkdir -p "$DB_DUMP_DIR"

if ! mysqldump --single-transaction --quick --lock-tables=false -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" | gzip > "$DUMP_FILE"; then
    printf 'FAILED|Database-back-up mislukt: %s | Dump mislukt\n' "$DISPLAY_DATE" > "$STATUS_FILE"
    printf '%s | ERROR | Database-back-up mislukt | Dump mislukt\n' "$DISPLAY_DATE" >> "$HISTORY_FILE"
    exit 1
fi

find "$DB_DUMP_DIR" -type f -name 'nextcloud-db-*.sql.gz' -mtime +"${DATABASE_RETENTION_DAYS:-30}" -delete

SSH_OPTS="$(ssh_options)"

if rsync -a -e "ssh $SSH_OPTS" "$DB_DUMP_DIR/" "${BACKUP_USER}@${BACKUP_HOST}:${BACKUP_PATH%/}/database/"; then
    printf 'OK|Database-back-up geslaagd: %s\n' "$DISPLAY_DATE" > "$STATUS_FILE"
    printf '%s | OK | Database-back-up geslaagd\n' "$DISPLAY_DATE" >> "$HISTORY_FILE"
    RC=0
else
    RC=$?
    printf 'FAILED|Database-back-up mislukt: %s | Exitcode: %s\n' "$DISPLAY_DATE" "$RC" > "$STATUS_FILE"
    printf '%s | ERROR | Database-back-up mislukt | Exitcode: %s\n' "$DISPLAY_DATE" "$RC" >> "$HISTORY_FILE"
fi

tail -20 "$HISTORY_FILE" > "$HISTORY_FILE.tmp" && mv "$HISTORY_FILE.tmp" "$HISTORY_FILE"
exit "$RC"
