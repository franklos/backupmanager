#!/usr/bin/python3 -I
"""Remove only manifest-owned client files; provider files/storage are preserved."""
import argparse
import json
import os
import shutil
import subprocess
from pathlib import Path

def main():
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--apply', action='store_true')
    modes.add_argument('--destdir', type=Path)
    parser.add_argument('--purge', action='store_true')
    args = parser.parse_args()
    root = args.destdir.resolve() if args.destdir else Path('/')
    if args.destdir and root == Path('/'):
        parser.error('Cannot stage at /')
    if args.apply and os.geteuid() != 0:
        parser.error('Root required')
    file = root / 'usr/local/share/backupmanager/client-files.json'
    provider_manifest = root / 'usr/local/share/backupmanager/provider-files.json'
    protected = set(json.loads(provider_manifest.read_text())) if provider_manifest.exists() else set()
    paths = json.loads(file.read_text())
    if args.apply:
        subprocess.run(['systemctl', 'disable', '--now', 'backupmanager.timer'], check=False)
        subprocess.run(['systemctl', 'stop', 'backupmanager.service', 'backupmanager-job@*.service'], check=True)
        cfg = json.loads(Path('/etc/backupmanager/runtime.json').read_text())
        if args.purge:
            subprocess.run(['runuser', '-u', cfg['nc_user'], '--', 'php', cfg['nc_path'] + '/occ', 'app:remove', 'backupstatus'], check=True)
        else:
            subprocess.run(['runuser', '-u', cfg['nc_user'], '--', 'php', cfg['nc_path'] + '/occ', 'app:disable', 'backupstatus'], check=True)
    for item in paths:
        if item in protected:
            continue
        target = root / item.lstrip('/')
        if '..' in Path(item).parts or not target.resolve().is_relative_to(root):
            raise ValueError('Unsafe installation manifest')
        target.unlink(missing_ok=True)
    file.unlink()
    if args.purge:
        for directory in ('etc/backupmanager', 'var/lib/backupmanager', 'var/log/backupmanager'):
            target = root / directory
            if target.is_symlink():
                raise ValueError('Unsafe purge path')
            if target.exists():
                shutil.rmtree(target)
    if args.apply:
        subprocess.run(['systemctl', 'daemon-reload'], check=True)
    print('Client removed; provider and remote backup data preserved.')

if __name__ == '__main__':
    main()
