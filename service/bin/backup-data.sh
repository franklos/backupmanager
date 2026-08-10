#!/bin/bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/backup-common.sh"
load_config
HISTORY_FILE="$STATUS_DIR/data-history.txt"
STATUS_FILE="$STATUS_DIR/data-status.txt"
START_FILE="$STATUS_DIR/data-start.ts"

date +%s > "$START_FILE"
printf '%s | START | Data-back-up gestart\n' "$(TZ=Europe/Amsterdam date '+%d-%m-%Y %H:%M')" >> "$HISTORY_FILE"

SSH_OPTS="$(ssh_options)"

rsync -aHAX --numeric-ids --delete --partial --info=progress2 \
  -e "ssh $SSH_OPTS" \
  "${DATA_PATH%/}/" \
  "${BACKUP_USER}@${BACKUP_HOST}:${BACKUP_PATH%/}/data/"

RC=$?
START=$(cat "$START_FILE" 2>/dev/null || echo 0)
END=$(date +%s)
DUR=$((END-START))
H=$((DUR/3600))
M=$(((DUR%3600)/60))
NOW="$(now)"

case "$RC" in
  0)
    printf 'OK|Data-back-up geslaagd: %s | Duur: %su %sm\n' "$NOW" "$H" "$M" > "$STATUS_FILE"
    printf '%s | OK | Data-back-up geslaagd | Duur: %su %sm\n' "$NOW" "$H" "$M" >> "$HISTORY_FILE"
    ;;
  24)
    printf 'ISSUE|Data-back-up afgerond met veranderde bronbestanden: %s | Duur: %su %sm\n' "$NOW" "$H" "$M" > "$STATUS_FILE"
    printf '%s | ISSUE | Bronbestanden veranderden tijdens back-up | Duur: %su %sm\n' "$NOW" "$H" "$M" >> "$HISTORY_FILE"
    ;;
  *)
    printf 'FAILED|Data-back-up mislukt: %s | Exitcode: %s\n' "$NOW" "$RC" > "$STATUS_FILE"
    printf '%s | ERROR | Data-back-up mislukt | Exitcode: %s\n' "$NOW" "$RC" >> "$HISTORY_FILE"
    ;;
esac

tail -20 "$HISTORY_FILE" > "$HISTORY_FILE.tmp" && mv "$HISTORY_FILE.tmp" "$HISTORY_FILE"
exit "$RC"
