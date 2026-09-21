#!/usr/bin/env python3
"""Deployment-only token provisioning; never prints the generated credential."""
import grp
import os
import secrets
from pathlib import Path
if os.geteuid() != 0:
    raise SystemExit('Run as root on the provider during deployment')
path = Path('/etc/backupmanager-provider/management-token')
fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
with os.fdopen(fd, 'w') as file:
    file.write(secrets.token_hex(32) + '\n')
os.chown(path, 0, grp.getgrnam('bmprovider').gr_gid)
print('Management token created. Transfer it privately only to authorized management installations.')
