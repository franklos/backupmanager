"""Enrollment helpers using data-only local configuration."""
import hashlib
import json
import os
import pwd
import re
import socket
import subprocess
import sys
from pathlib import Path
from .config import atomic_json, load, validate
from .control import CONFIG, payload
from .engine import lock
from .phpconfig import parse
from .provider_helpers import key


def source_id(cfg):
    if cfg['source_id'] and cfg['source_id'] != 'example.nextcloud.local':
        return cfg['source_id']
    config = parse((Path(cfg['nc_path']) / 'config/config.php').read_text())
    value = socket.getfqdn() + ':' + str(config.get('instanceid', ''))
    return 'nc-' + hashlib.sha256(value.encode()).hexdigest()[:32]


def public_key(path):
    """Return a validated public key, deriving it from the private key if needed."""
    private = Path(path)
    public = Path(str(private) + '.pub')
    if public.exists():
        return key(public.read_text().strip())
    if not private.is_file() or private.is_symlink():
        raise ValueError('SSH private key is unavailable')
    result = subprocess.run(['ssh-keygen', '-y', '-f', str(private)], check=True,
                            capture_output=True, text=True)
    return key(result.stdout.strip())


def request_info(cfg, recovery=False):
    directory = Path(cfg['runtime']) / ('recovery' if recovery else '.ssh')
    directory.mkdir(mode=0o700, exist_ok=True)
    paths = [directory / 'id_ed25519', directory / 'id_ed25519_restore'] if recovery else [Path(cfg['ssh_key']), Path(cfg['ssh_read_key'])]
    account = pwd.getpwnam('backupmgr')
    if recovery:
        for path in paths:
            if not path.exists():
                subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(path)], check=True, capture_output=True)
            os.chown(path, account.pw_uid, account.pw_gid)
    identity = source_id(cfg)
    if cfg['source_id'] != identity:
        cfg['source_id'] = identity
        atomic_json(CONFIG, cfg)
    return {'source_id': identity, 'public_key': public_key(paths[0]),
            'restore_public_key': public_key(paths[1])}


def activate(cfg):
    directory = Path(cfg['runtime']) / 'recovery'
    for name, target in [('id_ed25519', cfg['ssh_key']), ('id_ed25519_restore', cfg['ssh_read_key'])]:
        for suffix in ('', '.pub'):
            src, dest = directory / (name + suffix), Path(target + suffix)
            if src.exists():
                os.replace(src, dest)
            elif not dest.exists():
                raise ValueError('Recovery key missing')


def main():
    os.umask(0o077)
    try:
        if len(sys.argv) != 1:
            raise ValueError('This helper accepts JSON on standard input only')
        cfg = load()
        action = Path(sys.argv[0]).name
        with lock(cfg['runtime']):
            if action in ('backupmanager-request-info', 'backupmanager-recovery-request-info'):
                result = request_info(cfg, 'recovery-request' in action)
            elif action == 'backupmanager-activate-recovery-key':
                activate(cfg)
                result = {}
            elif action == 'backupmanager-apply-provider-config':
                if cfg['destination'] != 'ssh' or cfg['credential_mode'] != 'managed':
                    raise ValueError('Selected storage changed while enrollment was pending')
                data = payload()
                identity = data.get('client_id', '')
                if not re.fullmatch(r'BM-[0-9]{6}', identity) or data.get('path') != '/':
                    raise ValueError('Invalid managed provider identity/path')
                cfg.update(client_id=identity, ssh_host=data['host'], ssh_port=int(data['port']),
                           ssh_user=data['user'], ssh_path='/', destination='ssh', credential_mode='managed')
                atomic_json(CONFIG, validate(cfg))
                result = {}
            else:
                raise ValueError('Unknown client helper')
        print(json.dumps({'success': True} | result))
    except Exception:
        print(json.dumps({'success': False, 'error': 'Enrollment helper failed; check installation and configuration'}))
        return 1
    return 0
