#!/usr/bin/env bash
set -euo pipefail

NEXTCLOUD_ROOT="${NEXTCLOUD_ROOT:-/var/www/nextcloud}"
APP_SOURCE="/home/admin/backupmanager/app/backupstatus"
APP_TARGET="${NEXTCLOUD_ROOT}/apps/backupstatus"

CONFIG_SOURCE="/home/admin/backupmanager/service/config/backupmanager.conf.example"
CONFIG_DIR="/etc/backupmanager"
CONFIG_FILE="${CONFIG_DIR}/backupmanager.conf"

RUNTIME_DIR="/var/lib/backupmanager"
STATUS_DIR="${RUNTIME_DIR}/status"
DB_DUMP_DIR="${RUNTIME_DIR}/database"
SSH_DIR="${RUNTIME_DIR}/.ssh"

LOG_DIR="/var/log/backupmanager"

SERVICE_SOURCE="/home/admin/backupmanager/service"

BACKUP_USER="backupmgr"
BACKUP_GROUP="backupmgr"

if [[ $EUID -ne 0 ]]; then
    echo "Run this installer with sudo/root."
    exit 1
fi

if [[ ! -f "${NEXTCLOUD_ROOT}/occ" ]]; then
    echo "Nextcloud not found at ${NEXTCLOUD_ROOT}"
    exit 1
fi

if [[ ! -d "$APP_SOURCE" ]]; then
    echo "Backup Manager app source not found."
    exit 1
fi

if [[ ! -f "$CONFIG_SOURCE" ]]; then
    echo "Example configuration not found."
    exit 1
fi

if ! id "$BACKUP_USER" >/dev/null 2>&1; then
    useradd \
        --system \
        --home-dir "$RUNTIME_DIR" \
        --create-home \
        --shell /usr/sbin/nologin \
        "$BACKUP_USER"
fi

# Backup Manager moet Nextcloud-config en data kunnen lezen.
usermod -aG www-data "$BACKUP_USER"

install -d -o root -g "$BACKUP_GROUP" -m 0750 "$CONFIG_DIR"
install -d -o "$BACKUP_USER" -g "$BACKUP_GROUP" -m 0750 "$RUNTIME_DIR"
install -d -o "$BACKUP_USER" -g www-data -m 2750 "$STATUS_DIR"
install -d -o "$BACKUP_USER" -g "$BACKUP_GROUP" -m 0750 "$DB_DUMP_DIR"
install -d -o "$BACKUP_USER" -g "$BACKUP_GROUP" -m 0700 "$SSH_DIR"
install -d -o "$BACKUP_USER" -g "$BACKUP_GROUP" -m 0750 "$LOG_DIR"

if [[ ! -f "$CONFIG_FILE" ]]; then
    install -o root -g "$BACKUP_GROUP" -m 0640 "$CONFIG_SOURCE" "$CONFIG_FILE"
fi

if [[ ! -f "${SSH_DIR}/id_ed25519" ]]; then
    sudo -u "$BACKUP_USER" ssh-keygen \
        -q \
        -t ed25519 \
        -N '' \
        -f "${SSH_DIR}/id_ed25519" \
        -C "${BACKUP_USER}@$(hostname)"
fi


# Backup runtime scripts
for script in backup-common.sh backup-database.sh backup-data.sh backup-run.sh; do
    install -o root -g root -m 0755 \
        "${SERVICE_SOURCE}/bin/${script}" \
        "/usr/local/bin/${script}"
done

install -o root -g root -m 0755 \
    "${SERVICE_SOURCE}/sbin/backupmanager-request-info" \
    /usr/local/sbin/backupmanager-request-info

install -o root -g root -m 0755 \
    "${SERVICE_SOURCE}/sbin/backupmanager-schedule" \
    /usr/local/sbin/backupmanager-schedule

install -o root -g root -m 0755 \
    "${SERVICE_SOURCE}/sbin/backupmanager-test-connection" \
    /usr/local/sbin/backupmanager-test-connection

install -o root -g root -m 0755 \
    "${SERVICE_SOURCE}/sbin/backupmanager-apply-provider-config" \
    /usr/local/sbin/backupmanager-apply-provider-config


install -o root -g root -m 0755     "/home/admin/backupmanager/installer/uninstall.sh"     /usr/local/sbin/backupmanager-uninstall-now

install -o root -g root -m 0755     "${SERVICE_SOURCE}/sbin/backupmanager-uninstall-client"     /usr/local/sbin/backupmanager-uninstall-client

install -o root -g root -m 0644 \
    "${SERVICE_SOURCE}/systemd/backupmanager.service" \
    /etc/systemd/system/backupmanager.service

install -o root -g root -m 0644 \
    "${SERVICE_SOURCE}/systemd/backupmanager.timer" \
    /etc/systemd/system/backupmanager.timer

install -d -o root -g root -m 0755 /etc/systemd/system/backupmanager.timer.d

if [[ -f "${SERVICE_SOURCE}/timer.d/schedule.conf" ]]; then
    install -o root -g root -m 0644 \
        "${SERVICE_SOURCE}/timer.d/schedule.conf" \
        /etc/systemd/system/backupmanager.timer.d/schedule.conf
fi

cat > /etc/sudoers.d/backupmanager <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-request-info
www-data ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-schedule *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-test-connection *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-apply-provider-config *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-uninstall-client keep-data
www-data ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-uninstall-client purge
EOF

chmod 0440 /etc/sudoers.d/backupmanager

if ! visudo -cf /etc/sudoers.d/backupmanager >/dev/null; then
    echo "Invalid sudoers configuration."
    rm -f /etc/sudoers.d/backupmanager
    exit 1
fi

rm -rf "$APP_TARGET"
cp -a "$APP_SOURCE" "$APP_TARGET"
chown -R www-data:www-data "$APP_TARGET"

systemctl daemon-reload
systemctl enable --now backupmanager.timer

sudo -u www-data php "${NEXTCLOUD_ROOT}/occ" app:enable backupstatus

echo
echo "Backup Manager installed."
echo "Configuration: ${CONFIG_FILE}"
echo "SSH public key:"
cat "${SSH_DIR}/id_ed25519.pub"
