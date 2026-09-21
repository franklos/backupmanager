"""Root helpers: fixed provider root, validated keys, atomic locked authorization."""
import base64
import contextlib
import fcntl
import json
import os
import pwd
import re
import shutil
import struct
import sys
import tempfile
from pathlib import Path

ROOT = Path('/var/lib/backupmanager-provider')
ACCOUNT = Path('/var/lib/backupmanager-provider-account')


def client_id(value):
    if not re.fullmatch(r'BM-[0-9]{6}', value):
        raise ValueError('Invalid client ID')
    return value


def key(value):
    parts = value.strip().split()
    if len(parts) < 2 or parts[0] != 'ssh-ed25519' or '\n' in value or '\r' in value:
        raise ValueError('Only single-line Ed25519 public keys are supported')
    raw = base64.b64decode(parts[1], validate=True)
    if len(raw) != 51 or raw[:19] != struct.pack('>I', 11) + b'ssh-ed25519' + struct.pack('>I', 32):
        raise ValueError('Invalid SSH wire key')
    return 'ssh-ed25519 ' + parts[1]


def target(identity):
    client_id(identity)
    if ROOT.is_symlink():
        raise ValueError('Unsafe storage root')
    path = ROOT / identity
    if path.is_symlink() or path.resolve() != ROOT.resolve() / identity:
        raise ValueError('Unsafe client storage path')
    return path


@contextlib.contextmanager
def authorization_lock():
    with open(ACCOUNT / '.authorization.lock', 'a') as file:
        fcntl.flock(file, fcntl.LOCK_EX)
        yield


def write_keys(identity, lines):
    authorized = ACCOUNT / '.ssh/authorized_keys'
    existing = authorized.read_text().splitlines() if authorized.exists() else []
    markers = ('bm-client=' + identity, 'bm-restore=' + identity)
    preserved = [line for line in existing if not any(line.endswith(' ' + marker) for marker in markers)]
    # A key shared across client roots would make OpenSSH select an ambiguous restriction.
    for line in lines:
        public = ' '.join(line.split()[-3:-1])
        if any(' ' + public + ' ' in old for old in preserved):
            raise ValueError('Public key is already assigned to another client')
    fd, name = tempfile.mkstemp(dir=authorized.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write('\n'.join(preserved + lines) + '\n')
            os.fchmod(stream.fileno(), 0o644)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, authorized)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def install(data):
    identity = client_id(str(data['client_id']))
    write, read = key(data['write_key']), key(data['read_key'])
    if write == read:
        raise ValueError('Read/write keys must be distinct')
    path = target(identity)
    with authorization_lock():
        path.mkdir(mode=0o700, exist_ok=True)
        account = pwd.getpwnam('backupstore')
        os.chown(path, account.pw_uid, account.pw_gid)
        lines = [f'restrict,command="/usr/bin/rrsync -{mode} -munge {path}" {public} {marker}={identity}'
                 for mode, public, marker in [('wo', write, 'bm-client'), ('ro', read, 'bm-restore')]]
        write_keys(identity, lines)


def remove(identity, delete=False):
    path = target(identity)
    with authorization_lock():
        # Revoke BOTH keys before any deletion; a failure must not retain write access.
        write_keys(identity, [])
        if delete and path.exists():
            if not shutil.rmtree.avoids_symlink_attacks:
                raise RuntimeError('This platform lacks safe recursive removal')
            root_device = path.stat().st_dev
            for current, directories, _ in os.walk(path, followlinks=False):
                if Path(current).stat().st_dev != root_device:
                    raise ValueError('Mounted storage requires operator removal')
            shutil.rmtree(path)


def usage(identity):
    path = target(identity)
    if not path.is_dir():
        raise ValueError('Client storage unavailable')
    used = 0
    for directory, _, files in os.walk(path, followlinks=False):
        for name in files:
            file = Path(directory) / name
            import stat
            info = file.stat(follow_symlinks=False)
            if stat.S_ISREG(info.st_mode):
                used += info.st_size
    print('capacity_bytes=0\nused_bytes=' + str(used) + '\nfree_bytes=0')


def main():
    os.umask(0o077)
    action = Path(sys.argv[0]).name
    try:
        if action == 'backupmanager-install-authorized-keys' and len(sys.argv) == 1:
            raw = sys.stdin.buffer.read(8193)
            if len(raw) > 8192:
                raise ValueError('Request too large')
            install(json.loads(raw))
        elif len(sys.argv) == 2:
            identity = client_id(sys.argv[1])
            if action == 'backupmanager-storage-usage':
                usage(identity)
            elif action in ('backupmanager-remove-authorized-key', 'backupmanager-remove-storage'):
                remove(identity, action.endswith('remove-storage'))
            else:
                raise ValueError('Unknown helper')
        else:
            raise ValueError('Invalid arguments')
    except Exception:
        print('Provider helper failed; check validated input and installation', file=sys.stderr)
        return 1
    return 0
