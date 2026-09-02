#!/bin/bash
set -uo pipefail

/usr/local/bin/backup-database.sh
DB_RC=$?

/usr/local/bin/backup-data.sh
DATA_RC=$?

/usr/local/bin/backup-config.sh
CONFIG_RC=$?

if [ "$DB_RC" -ne 0 ]; then
    exit "$DB_RC"
fi

if [ "$DATA_RC" -ne 0 ]; then
    exit "$DATA_RC"
fi

exit "$CONFIG_RC"
