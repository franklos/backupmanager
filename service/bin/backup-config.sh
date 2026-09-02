#!/bin/bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# shellcheck disable=SC1091
source "$SCRIPT_DIR/backup-common.sh"
load_config

HISTORY_FILE="$STATUS_DIR/config-history.txt"
STATUS_FILE="$STATUS_DIR/config-status.txt"
START_FILE="$STATUS_DIR/config-start.ts"

CONFIG_PATH="${NC_PATH%/}/config"

if [ ! -d "$CONFIG_PATH" ]; then
    NOW="$(now)"
    printf 'FAILED|Config-back-up mislukt: %s | Config-directory ontbreekt\n' "$NOW" > "$STATUS_FILE"
    printf '%s | ERROR | Config-back-up mislukt | Config-directory ontbreekt\n' "$NOW" >> "$HISTORY_FILE"
    exit 2
fi

date +%s > "$START_FILE"
printf '%s | START | Config-back-up gestart\n' "$(now)" >> "$HISTORY_FILE"

SSH_OPTS="$(ssh_options)"

rsync \
    -a \
    --delete \
    -e "ssh $SSH_OPTS" \
    "${CONFIG_PATH%/}/" \
    "${BACKUP_USER}@${BACKUP_HOST}:${BACKUP_PATH%/}/config/"

RC=$?

START=$(cat "$START_FILE" 2>/dev/null || echo 0)
END=$(date +%s)
DUR=$((END-START))
H=$((DUR/3600))
M=$(((DUR%3600)/60))
NOW="$(now)"

case "$RC" in
    0)
        printf 'OK|Config-back-up geslaagd: %s | Duur: %su %sm\n' "$NOW" "$H" "$M" > "$STATUS_FILE"
        printf '%s | OK | Config-back-up geslaagd | Duur: %su %sm\n' "$NOW" "$H" "$M" >> "$HISTORY_FILE"
        ;;
    24)
        printf 'ISSUE|Config-back-up afgerond met veranderde bronbestanden: %s | Duur: %su %sm\n' "$NOW" "$H" "$M" > "$STATUS_FILE"
        printf '%s | ISSUE | Configbestanden veranderden tijdens back-up | Duur: %su %sm\n' "$NOW" "$H" "$M" >> "$HISTORY_FILE"
        ;;
    *)
        printf 'FAILED|Config-back-up mislukt: %s | Exitcode: %s\n' "$NOW" "$RC" > "$STATUS_FILE"
        printf '%s | ERROR | Config-back-up mislukt | Exitcode: %s\n' "$NOW" "$RC" >> "$HISTORY_FILE"
        ;;
esac

tail -20 "$HISTORY_FILE" > "$HISTORY_FILE.tmp" &&
    mv "$HISTORY_FILE.tmp" "$HISTORY_FILE"

exit "$RC"
