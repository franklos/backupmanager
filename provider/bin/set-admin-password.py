#!/usr/bin/env python3
"""Deployment-only tool: create/rotate the provider administrator password hash."""
import getpass
import grp
import os
import subprocess
from pathlib import Path
import tempfile
if os.geteuid() != 0:
    raise SystemExit('Run as root on the provider during deployment')
password = getpass.getpass('New administrator password (at least 16 characters): ')
if len(password) < 16 or password != getpass.getpass('Repeat password: '):
    raise SystemExit('Password too short or confirmation differs')
result = subprocess.run(['php', '-r', 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'], input=password.encode(), capture_output=True, check=True)
password = None
path = Path('/etc/backupmanager-provider/admin-password.hash')
fd, temporary = tempfile.mkstemp(dir=path.parent)
with os.fdopen(fd, 'wb') as file:
    file.write(result.stdout + b'\n')
    os.fchmod(file.fileno(), 0o640)
os.chown(temporary, 0, grp.getgrnam('bmprovider').gr_gid)
os.replace(temporary, path)
print('Administrator credential rotated; previous sessions are invalidated.')
