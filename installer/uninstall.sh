#!/usr/bin/env bash
set -euo pipefail

NEXTCLOUD_ROOT="${NEXTCLOUD_ROOT:-/var/www/nextcloud}"
APP_TARGET="${NEXTCLOUD_ROOT}/apps/backupstatus"

CONFIG_DIR="/etc/backupmanager"
RUNTIME_DIR="/var/lib/backupmanager"
LOG_DIR="/var/log/backupmanager"

BACKUP_USER="backupmgr"

PURGE=0

if [[ "${1:-}" == "--purge" ]]; then
    PURGE=1
elif [[ $# -gt 0 ]]; then
    echo "Usage: sudo $0 [--purge]"
    exit 1
fi

if [[ $EUID -ne 0 ]]; then
    echo "Run this uninstaller with sudo/root."
    exit 1
fi

echo "Removing Backup Manager client..."

systemctl disable --now backupmanager.timer 2>/dev/null || true
systemctl stop backupmanager.service 2>/dev/null || true

if [[ -f "${NEXTCLOUD_ROOT}/occ" ]]; then
    if [[ $PURGE -eq 1 ]]; then
        mapfile -t APP_KEYS < <(
            sudo -u www-data php "${NEXTCLOUD_ROOT}/occ"                 config:list backupstatus --output=json 2>/dev/null             | jq -r '.apps.backupstatus | keys[]' 2>/dev/null
        )

        for KEY in "${APP_KEYS[@]}"; do
            sudo -u www-data php "${NEXTCLOUD_ROOT}/occ"                 config:app:delete backupstatus "$KEY" --quiet 2>/dev/null || true
        done
    fi

    sudo -u www-data php "${NEXTCLOUD_ROOT}/occ" app:disable backupstatus 2>/dev/null || true
    sudo -u www-data php "${NEXTCLOUD_ROOT}/occ" app:remove backupstatus 2>/dev/null || true
fi

rm -rf "$APP_TARGET"

rm -f \
    /etc/systemd/system/backupmanager.service \
    /etc/systemd/system/backupmanager.timer \
    /etc/sudoers.d/backupmanager \
    /usr/local/sbin/backupmanager-request-info \
    /usr/local/sbin/backupmanager-schedule \
    /usr/local/sbin/backupmanager-test-connection

rm -rf /etc/systemd/system/backupmanager.timer.d

systemctl daemon-reload
systemctl reset-failed 2>/dev/null || true

if [[ $PURGE -eq 1 ]]; then
    echo "Purging configuration, runtime data and client account..."

    rm -rf \
        "$CONFIG_DIR" \
        "$RUNTIME_DIR" \
        "$LOG_DIR"

    userdel "$BACKUP_USER" 2>/dev/null || true

    echo "Backup Manager client fully purged."
else
    echo
    echo "Backup Manager client removed."
    echo "Preserved:"
    echo "  $CONFIG_DIR"
    echo "  $RUNTIME_DIR"
    echo "  $LOG_DIR"
    echo "  user: $BACKUP_USER"
    echo
    echo "Use --purge to remove these as well."
fi

echo
echo "Provider components were NOT touched."
