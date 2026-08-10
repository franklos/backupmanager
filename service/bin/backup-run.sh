#!/bin/bash
set -uo pipefail

/usr/local/bin/backup-database.sh
DB_RC=$?

/usr/local/bin/backup-data.sh
DATA_RC=$?

if [ "$DB_RC" -ne 0 ]; then
    exit "$DB_RC"
fi

exit "$DATA_RC"
