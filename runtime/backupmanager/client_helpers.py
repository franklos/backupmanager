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
from .config import atomic_json, load, validate, remember_storage
from .control import CONFIG, payload
from .engine import lock
from .phpconfig import parse
from .provider_helpers import key
from .errors import failure_info, log_failure


def source_id(cfg):
    if cfg['source_id'] and cfg['source_id'] != 'example.nextcloud.local':
        return cfg['source_id']
    config = parse((Path(cfg['nc_path']) / 'config/config.php').read_text())
    value = socket.getfqdn() + ':' + str(config.get('instanceid', ''))
    return 'nc-' + hashlib.sha256(value.encode()).hexdigest()[:32]


def public_key(path):
    """Derive the authoritative public key; companion files may be stale after recovery."""
    private = Path(path)
    if not private.is_file() or private.is_symlink():
        raise ValueError('SSH private key is unavailable')
    result = subprocess.run(['ssh-keygen', '-y', '-f', str(private)], check=True,
                            capture_output=True, text=True)
    return key(result.stdout.strip())


def ensure_keys(cfg):
    """Upgrade write-only clients without rotating or overwriting their existing key."""
    paths = [Path(cfg['ssh_key']), Path(cfg['ssh_read_key'])]
    if paths[0] == paths[1]:
        raise ValueError('Write and read key paths must be distinct')
    account = pwd.getpwnam('backupmgr')
    for path in paths:
        if path.is_symlink():
            raise ValueError('Symlink private key refused')
        if not path.exists():
            subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(path)], check=True, capture_output=True)
        os.chown(path, account.pw_uid, account.pw_gid)
        path.chmod(0o600)
    if public_key(paths[0]) == public_key(paths[1]):
        raise ValueError('Write and read keys must be distinct; review existing keys')


def request_info(cfg, recovery=False):
    directory = Path(cfg['runtime']) / ('recovery' if recovery else '.ssh')
    if directory.is_symlink():
        raise ValueError('Symlink key directory refused')
    directory.mkdir(mode=0o700, exist_ok=True)
    paths = [directory / 'id_ed25519', directory / 'id_ed25519_restore'] if recovery else [Path(cfg['ssh_key']), Path(cfg['ssh_read_key'])]
    account = pwd.getpwnam('backupmgr')
    if recovery:
        for path in paths:
            if path.is_symlink():
                raise ValueError('Symlink staged private key refused')
            if not path.exists():
                subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(path)], check=True, capture_output=True)
            os.chown(path, account.pw_uid, account.pw_gid)
            path.chmod(0o600)
    identity = source_id(cfg)
    if cfg['source_id'] != identity:
        cfg['source_id'] = identity
        atomic_json(CONFIG, cfg)
    result = {'source_id': identity, 'public_key': public_key(paths[0]),
              'restore_public_key': public_key(paths[1])}
    if result['public_key'] == result['restore_public_key']:
        raise ValueError('Recovery write and read keys must be distinct')
    if recovery:
        atomic_json(directory / 'keys.json', {name: result[name] for name in ('public_key', 'restore_public_key')})
    return result


def activate(cfg, approved=None):
    directory = Path(cfg['runtime']) / 'recovery'
    pairs = [('id_ed25519', cfg['ssh_key'], 'public_key'),
             ('id_ed25519_restore', cfg['ssh_read_key'], 'restore_public_key')]
    manifest = directory / 'keys.json'
    if not manifest.exists():
        # Compatibility with an older helper is safe only with both staged keys.
        expected = {field: public_key(directory / name) for name, _, field in pairs}
        atomic_json(manifest, expected)
    expected = json.loads(manifest.read_text())
    if approved is not None and any(key(approved.get(field, '')) != key(expected[field])
                                    for field in ('public_key', 'restore_public_key')):
        raise ValueError('Staged recovery keys do not match the approved request; retry the current request')
    if key(expected['public_key']) == key(expected['restore_public_key']):
        raise ValueError('Recovery keys must be distinct')
    # Validate the whole pair before changing either target. The manifest also
    # makes retry after an interrupted pair activation safe and idempotent.
    for name, target, field in pairs:
        src = directory / name
        candidate = src if src.exists() else Path(target)
        if (public_key(candidate) != key(expected[field]) or Path(target).is_symlink()
                or Path(str(target) + '.pub').is_symlink()):
            raise ValueError('Recovery key does not match the requested pair')
    for name, target, field in pairs:
        src, dest = directory / name, Path(target)
        if src.exists():
            os.replace(src, dest)
        public = Path(str(dest) + '.pub')
        if public.is_symlink():
            raise ValueError('Symlink public key refused')
        # This is public material; the private key remains owned by backupmgr, 0600.
        public.write_text(key(expected[field]) + '\n')
        public.chmod(0o644)
        (directory / (name + '.pub')).unlink(missing_ok=True)


def provider_config(cfg, data):
    identity = data.get('client_id', '')
    if (not isinstance(identity, str) or not re.fullmatch(r'BM-[0-9]{6}', identity)
            or data.get('path') != '/' or data.get('user') != 'backupstore'
            or not isinstance(data.get('host'), str) or not data['host']
            or type(data.get('port')) is not int):
        raise ValueError('Invalid managed provider connection')
    return remember_storage(validate(remember_storage(cfg) | dict(client_id=identity, ssh_host=data['host'],
        ssh_port=data['port'], ssh_user=data['user'], ssh_path='/',
        destination='ssh', credential_mode='managed')))


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
                # Read the current assignment under the same lock used for publishing it.
                cfg = load()
                data = payload()
                if data.get('select_managed') is True:
                    existing = cfg['storage_profiles'].get('ssh_managed', {}).get('client_id') or cfg['client_id']
                    if not existing or data.get('client_id') != existing or data.get('activate_recovery') is True:
                        raise ValueError('Assignment refresh requires the existing client without key activation')
                elif cfg['destination'] != 'ssh' or cfg['credential_mode'] != 'managed':
                    raise ValueError('Selected storage changed while enrollment was pending')
                cfg = provider_config(cfg, data)
                if data.get('activate_recovery') is True:
                    activate(cfg, data)
                atomic_json(CONFIG, cfg)
                result = {}
            else:
                raise ValueError('Unknown client helper')
        print(json.dumps({'success': True} | result))
    except Exception as error:
        log_failure(error, 'enrollment helper failure')
        print(json.dumps({'success': False, **failure_info(error)}))
        return 1
    return 0
