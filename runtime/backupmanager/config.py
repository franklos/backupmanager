"""Validated configuration; neither shell nor PHP configuration is executed."""
import json
import os
import re
import shlex
import tempfile
from pathlib import Path
from urllib.parse import urlsplit

DEFAULTS = {
    'destination': 'ssh', 'credential_mode': 'managed',
    'nc_path': '/var/www/nextcloud', 'nc_user': 'www-data',
    'data_path': '/var/www/nextcloud/data', 'runtime': '/var/lib/backupmanager',
    'ssh_host': '', 'ssh_port': 22, 'ssh_user': 'backupstore', 'ssh_path': '/',
    'ssh_key': '/var/lib/backupmanager/.ssh/id_ed25519',
    'ssh_read_key': '/var/lib/backupmanager/.ssh/id_ed25519_restore',
    'known_hosts': '/var/lib/backupmanager/.ssh/known_hosts',
    'source_id': '', 'client_id': '', 'retention_days': 30,
    'days': 'Mon,Tue,Wed,Thu,Fri,Sat,Sun', 'time': '02:00', 'timezone': 'UTC',
    'stale_hours': 48, 's3_endpoint': 'https://s3.amazonaws.com',
    's3_region': 'us-east-1', 's3_bucket': '', 's3_prefix': '',
    's3_credentials': '/etc/backupmanager/s3-credentials.json',
    's3_sse': '',
}
PUBLIC = {'destination', 'credential_mode', 'ssh_host', 'ssh_port', 'ssh_user',
          'ssh_path', 'retention_days', 'days', 'time', 'timezone', 'stale_hours',
          's3_endpoint', 's3_region', 's3_bucket', 's3_prefix', 's3_sse', 'client_id'}


def atomic_json(path, data, mode=0o600):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix='.bm-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as out:
            os.fchmod(out.fileno(), mode)
            json.dump(data, out, sort_keys=True)
            out.write('\n')
            out.flush()
            os.fsync(out.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def validate(values):
    if set(values) - set(DEFAULTS):
        raise ValueError('Unknown configuration keys')
    cfg = DEFAULTS | values
    for key, default in DEFAULTS.items():
        if type(cfg[key]) is not type(default):
            raise ValueError('Invalid configuration type: ' + key)
        if isinstance(cfg[key], str) and any(ord(c) < 32 for c in cfg[key]):
            raise ValueError('Control character in configuration: ' + key)
    if cfg['destination'] not in ('ssh', 'aws_s3', 's3_compatible'):
        raise ValueError('Unsupported destination')
    if cfg['credential_mode'] not in ('managed', 'manual'):
        raise ValueError('Invalid credential mode')
    for key in ('nc_path', 'data_path', 'runtime', 'ssh_key', 'ssh_read_key', 'known_hosts', 's3_credentials'):
        p = Path(cfg[key])
        if not p.is_absolute() or '..' in p.parts or str(p) == '/':
            raise ValueError('Unsafe path: ' + key)
    if cfg['nc_user'] == 'root' or not re.fullmatch(r'[a-z_][a-z0-9_-]*', cfg['nc_user']):
        raise ValueError('Invalid Nextcloud service user')
    if cfg['ssh_host'] and not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.-]*', cfg['ssh_host']):
        raise ValueError('Use a DNS name or IPv4 address for SSH')
    if not re.fullmatch(r'[a-z_][a-z0-9_-]*', cfg['ssh_user']):
        raise ValueError('Invalid SSH user')
    if not re.fullmatch(r'/[A-Za-z0-9_./-]*', cfg['ssh_path']) or '..' in Path(cfg['ssh_path']).parts:
        raise ValueError('Invalid SSH storage path')
    if not 1 <= cfg['ssh_port'] <= 65535 or not 1 <= cfg['retention_days'] <= 36500:
        raise ValueError('Invalid port or retention')
    if not 1 <= cfg['stale_hours'] <= 8760:
        raise ValueError('Invalid stale threshold')
    if not re.fullmatch(r'(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(,(Mon|Tue|Wed|Thu|Fri|Sat|Sun))*', cfg['days']):
        raise ValueError('Invalid schedule days')
    if not re.fullmatch(r'([01][0-9]|2[0-3]):[0-5][0-9]', cfg['time']):
        raise ValueError('Invalid schedule time')
    from zoneinfo import ZoneInfo
    ZoneInfo(cfg['timezone'])
    endpoint = urlsplit(cfg['s3_endpoint'])
    if endpoint.scheme != 'https' or not endpoint.hostname or endpoint.username or endpoint.password or endpoint.query or endpoint.fragment or endpoint.path not in ('', '/'):
        raise ValueError('S3 endpoint must be an HTTPS origin')
    if not re.fullmatch(r'[a-z0-9-]+', cfg['s3_region']):
        raise ValueError('Invalid S3 region')
    if cfg['s3_sse'] not in ('', 'AES256'):
        raise ValueError('Invalid S3 encryption setting')
    if cfg['destination'] != 'ssh':
        if not re.fullmatch(r'[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]', cfg['s3_bucket']):
            raise ValueError('Invalid S3 bucket')
        if not re.fullmatch(r'[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*', cfg['s3_prefix']):
            raise ValueError('An installation-specific S3 prefix is required')
    return cfg


def load(path='/etc/backupmanager/runtime.json'):
    with open(path) as stream:
        return validate(json.load(stream))


def legacy(path):
    """One-time non-executing import. Unsupported shell syntax fails closed."""
    mapping = {'NC_PATH': 'nc_path', 'NC_USER': 'nc_user', 'DATA_PATH': 'data_path',
               'BACKUP_HOST': 'ssh_host', 'BACKUP_PORT': 'ssh_port', 'BACKUP_USER': 'ssh_user',
               'BACKUP_PATH': 'ssh_path', 'SOURCE_ID': 'source_id', 'SSH_KEY': 'ssh_key',
               'DATABASE_RETENTION_DAYS': 'retention_days', 'TIMEZONE': 'timezone'}
    result = {}
    for line in Path(path).read_text().splitlines():
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        m = re.fullmatch(r'([A-Z_]+)=(.*)', line)
        if not m or any(c in m[2] for c in ('$', '`', ';', '\n')):
            raise ValueError('Legacy configuration contains unsupported shell syntax')
        parts = shlex.split(m[2], comments=True)
        if len(parts) > 1:
            raise ValueError('Invalid legacy assignment')
        if m[1] in mapping:
            key = mapping[m[1]]
            value = parts[0] if parts else ''
            result[key] = int(value) if type(DEFAULTS[key]) is int else value
    if 'ssh_key' in result:
        result['ssh_read_key'] = result['ssh_key'] + '_restore'
        result['known_hosts'] = str(Path(result['ssh_key']).parent / 'known_hosts')
    return validate(result)
