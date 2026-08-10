#!/bin/bash

CONFIG="/etc/backupmanager/backupmanager.conf"

load_config() {
    [ -r "$CONFIG" ] || {
        echo "Config ontbreekt: $CONFIG" >&2
        exit 2
    }

    # shellcheck disable=SC1090
    source "$CONFIG"

    STATUS_DIR="${STATUS_PATH:-/var/lib/backupmanager/status}"
    LOG_DIR="${LOG_PATH:-/var/log/backupmanager}"
    TIMEZONE="${TIMEZONE:-Europe/Amsterdam}"

    mkdir -p "$STATUS_DIR" "$LOG_DIR"
}

now() {
    TZ="$TIMEZONE" date '+%d-%m-%Y %H:%M'
}

duration() {
    local start="$1"
    local end
    local seconds
    local hours
    local minutes

    end=$(date +%s)
    seconds=$((end-start))
    hours=$((seconds/3600))
    minutes=$(((seconds%3600)/60))

    printf '%su %sm' "$hours" "$minutes"
}

ssh_options() {
    printf '%s' "-i $SSH_KEY -p $BACKUP_PORT -o UserKnownHostsFile=$(dirname "$SSH_KEY")/known_hosts -o ConnectTimeout=10"
}
