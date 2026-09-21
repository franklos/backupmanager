"""Unprivileged atomic install of a strictly literal Nextcloud configuration."""
import os
import sys
import tempfile
from pathlib import Path
from .phpconfig import parse, render


def main():
    if len(sys.argv) != 2 or os.geteuid() == 0:
        raise SystemExit('Invoke as the Nextcloud service user')
    target = Path(sys.argv[1])
    if target.name != 'config.php' or target.is_symlink() or target.parent.is_symlink():
        raise ValueError('Unsafe configuration target')
    data = sys.stdin.buffer.read(4 * 1024 * 1024 + 1).decode('utf-8')
    values = parse(data)
    if values.get('maintenance') is not True:
        raise ValueError('Restored configuration must retain maintenance mode')
    fd, temporary = tempfile.mkstemp(dir=target.parent)
    try:
        with os.fdopen(fd, 'w') as file:
            file.write('<?php\n$CONFIG = ' + render(values) + ';\n')
            os.fchmod(file.fileno(), 0o640)
        os.replace(temporary, target)
        for extra in target.parent.glob('*.config.php'):
            if extra.is_symlink():
                raise ValueError('Symlink configuration refused')
            extra.unlink()
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
