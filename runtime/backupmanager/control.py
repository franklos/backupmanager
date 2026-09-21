"""Fixed privileged entry points. Web callers cannot supply filesystem paths."""
import base64
import datetime as dt
import hashlib
import json
import os
import re
import subprocess
import sys
import uuid
from pathlib import Path

from .config import PUBLIC, atomic_json, load, validate
from .engine import Engine, lock, utcnow
from .storage import GENERATION, backend

class JobCancelled(RuntimeError):
    pass


from contextlib import contextmanager

@contextmanager
def job_lock(file):
    import fcntl
    with open(file.with_suffix('.lock'), 'a') as handle:
        fcntl.flock(handle, fcntl.LOCK_EX)
        yield


CONFIG = '/etc/backupmanager/runtime.json'
WEB_SETTINGS = PUBLIC - {'client_id'}


def payload():
    value = sys.stdin.buffer.read(65537)
    if len(value) > 65536:
        raise ValueError('Request too large')
    data = json.loads(value or b'{}')
    if not isinstance(data, dict):
        raise ValueError('JSON object required')
    return data


def host_status(cfg):
    result = {'host_trusted': False, 'host_key': '', 'host_fingerprint': '', 'host_error': ''}
    file = Path(cfg['known_hosts'])
    if not cfg['ssh_host'] or not file.is_file() or file.is_symlink():
        return result
    host = cfg['ssh_host'] if cfg['ssh_port'] == 22 else '[' + cfg['ssh_host'] + ']:' + str(cfg['ssh_port'])
    found = subprocess.run(['ssh-keygen', '-F', host, '-f', str(file)], capture_output=True, text=True, timeout=5)
    if found.returncode not in (0, 1):
        raise RuntimeError('Unable to read SSH host trust')
    for line in found.stdout.splitlines():
        parts = line.split()
        if not parts or parts[0].startswith('#'):
            continue
        if parts[0].startswith('@'):
            continue  # A CA or revoked key is not a directly pinned host key.
        if len(parts) < 3:
            continue
        key = ' '.join(parts[1:3])
        checked = subprocess.run(['ssh-keygen', '-l', '-E', 'sha256', '-f', '/dev/stdin'],
                                 input=key + '\n', capture_output=True, text=True, timeout=5)
        if checked.returncode == 0:
            result.update(host_trusted=True, host_key=key, host_fingerprint=checked.stdout.split()[1])
            break
    state = Path(cfg['runtime']) / 'status/host-verification.json'
    if state.exists():
        previous = json.loads(state.read_text())
        if previous.get('identity') == host_identity(cfg) and previous.get('error'):
            result.update(host_trusted=False, host_error=previous['error'])
    return result


def host_identity(cfg):
    return hashlib.sha256(json.dumps([cfg[k] for k in
        ('ssh_host', 'ssh_port', 'ssh_user', 'ssh_path', 'ssh_read_key', 'known_hosts')]).encode()
        + Path(cfg['known_hosts']).read_bytes()).hexdigest()


def host_trusted(cfg):
    return host_status(cfg)['host_trusted']


def verify_host(cfg):
    with lock(cfg['runtime']):
        status = host_status(cfg)
        if not status['host_key']:
            raise ValueError('No valid SSH host pin for the saved host and port; obtain a verified key from the provider')
        identity = host_identity(cfg)
        state = Path(cfg['runtime']) / 'status/host-verification.json'
        try:
            storage = backend(cfg)
            if cfg['destination'] != 'ssh':
                raise ValueError('SSH storage must be selected')
            storage.rsync(['--list-only', storage.remote('')], read=True, timeout=25)
        except Exception as failure:
            print('Backup Manager host verification: ' + type(failure).__name__ + ': ' + str(failure), file=sys.stderr)
            error = 'SSH host verification failed; check the pinned key, access and connectivity. See the server log.'
            atomic_json(state, {'identity': identity, 'error': error})
            raise RuntimeError(error) from None
        atomic_json(state, {'identity': identity, 'error': ''})
        return {'settings': host_status(cfg)}


def safe_status(cfg):
    file = Path(cfg['runtime']) / 'status/state.json'
    result = json.loads(file.read_text()) if file.exists() else {'state': 'missing'}
    if result.get('state') in ('ok', 'restored'):
        last = result.get('last_success')
        if not last or utcnow() - dt.datetime.fromisoformat(last) > dt.timedelta(hours=cfg['stale_hours']):
            result['state'] = 'stale'
    return result


def apply_schedule(cfg):
    path = Path('/etc/systemd/system/backupmanager.timer.d/schedule.conf')
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text('[Timer]\nOnCalendar=\nOnCalendar=' + cfg['days'] + ' *-*-* ' + cfg['time'] + ':00 ' + cfg['timezone'] + '\n')
    subprocess.run(['systemctl', 'daemon-reload'], check=True, capture_output=True)
    subprocess.run(['systemctl', 'enable', '--now', 'backupmanager.timer'], check=True, capture_output=True)
    subprocess.run(['systemctl', 'restart', 'backupmanager.timer'], check=True, capture_output=True)


def save(cfg, data):
    if set(data) - WEB_SETTINGS - {'s3_access_key', 's3_secret_key', 's3_session_token'}:
        raise ValueError('Unsupported settings')
    updated = validate(cfg | {k: v for k, v in data.items() if k in WEB_SETTINGS})
    with lock(cfg['runtime']):
        if updated['s3_endpoint'] != cfg['s3_endpoint'] and Path(cfg['s3_credentials']).exists() and not data.get('s3_secret_key'):
            raise ValueError('Supply new credentials when changing the S3 endpoint')
        new_credentials = None
        if data.get('s3_access_key') or data.get('s3_secret_key'):
            if not data.get('s3_access_key') or not data.get('s3_secret_key'):
                raise ValueError('Supply both S3 credential fields, or leave both blank')
            secrets = {k: data.get('s3_' + k, '') for k in ('access_key', 'secret_key', 'session_token')}
            if any(not isinstance(v, str) or '\n' in v or '\r' in v for v in secrets.values()):
                raise ValueError('Invalid credential format')
            new_credentials = Path(cfg['s3_credentials']).parent / ('.s3-credentials-' + uuid.uuid4().hex + '.json')
            atomic_json(new_credentials, secrets)
            updated['s3_credentials'] = str(new_credentials)
        try:
            # The config pointer and its new private credential file become visible together.
            apply_schedule(updated)
            atomic_json(CONFIG, updated)
        except Exception:
            if new_credentials is not None:
                new_credentials.unlink(missing_ok=True)
            try:
                apply_schedule(cfg)
            except Exception:
                pass  # Report failure; never publish mismatched credentials/configuration.
            raise RuntimeError('Settings could not be applied; review the system timer') from None
        previous = Path(cfg['s3_credentials'])
        if new_credentials is not None and previous.parent == new_credentials.parent and re.fullmatch(r'\.s3-credentials-[a-f0-9]{32}\.json', previous.name):
            previous.unlink(missing_ok=True)
    return {'settings': {k: updated[k] for k in PUBLIC}}


def trust(cfg, data):
    """Explicit out-of-band host key pinning; never TOFU/ssh-keyscan auto-accept."""
    if not data.get('key') and not data.get('fingerprint'):
        return verify_host(cfg)
    key = data.get('key', '')
    parts = key.strip().split()
    if len(parts) != 2 or parts[0] not in ('ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256'):
        raise ValueError('Supply an SSH host public key without a comment')
    raw = base64.b64decode(parts[1], validate=True)
    fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(raw).digest()).decode().rstrip('=')
    if data.get('fingerprint') != fingerprint:
        raise ValueError('Host key fingerprint does not match')
    if not cfg['ssh_host']:
        raise ValueError('Save SSH settings first')
    host = '[' + cfg['ssh_host'] + ']:' + str(cfg['ssh_port']) if cfg['ssh_port'] != 22 else cfg['ssh_host']
    with lock(cfg['runtime']):
        file = Path(cfg['known_hosts'])
        if file.is_symlink():
            raise ValueError('Symlink trust file refused')
        file.write_text(host + ' ' + ' '.join(parts) + '\n')
        os.chmod(file, 0o644)
    return verify_host(cfg)


def enqueue(cfg, data):
    action = data.get('action')
    if action not in ('backup', 'verify', 'restore', 'test', 'delete'):
        raise ValueError('Invalid job action')
    if action in ('verify', 'restore') and not GENERATION.fullmatch(str(data.get('generation', ''))):
        raise ValueError('Select a recovery point')
    if action == 'restore' and data.get('confirm') != 'RESTORE':
        raise ValueError('Restore confirmation required')
    if action == 'delete' and data.get('confirm') != 'DELETE':
        raise ValueError('Deletion confirmation required')
    if action == 'delete' and cfg['credential_mode'] == 'managed' and cfg['destination'] == 'ssh':
        raise ValueError('Managed deletion requires provider approval')
    kind = data.get('type', 'complete')
    if kind not in ('data', 'database', 'complete', 'disaster'):
        raise ValueError('Invalid restore type')
    job_id = uuid.uuid4().hex
    job = {'configuration_hash': hashlib.sha256(json.dumps(cfg, sort_keys=True).encode()).hexdigest(), 'id': job_id, 'action': action, 'generation': data.get('generation', ''),
           'type': kind, 'state': 'queued', 'created_at': utcnow().isoformat()}
    atomic_json(Path(cfg['runtime']) / 'jobs' / (job_id + '.json'), job)
    subprocess.run(['systemctl', 'start', 'backupmanager-job@' + job_id + '.service'], check=True, capture_output=True)
    return {'job': job}


def job_path(cfg, job_id):
    if not re.fullmatch(r'[a-f0-9]{32}', str(job_id)):
        raise ValueError('Invalid job ID')
    return Path(cfg['runtime']) / 'jobs' / (job_id + '.json')


def worker(job_id):
    cfg = load()
    file = job_path(cfg, job_id)
    job = json.loads(file.read_text())
    if job['state'] != 'queued':
        raise ValueError('Job is not queued')
    cancel = file.with_suffix('.cancel')
    def checkpoint():
        if cancel.exists():
            raise JobCancelled('Job cancelled before making restore changes')
    try:
        checkpoint()
        if job.get('configuration_hash') != hashlib.sha256(json.dumps(cfg, sort_keys=True).encode()).hexdigest():
            raise RuntimeError('Settings changed after this job was queued; submit it again')
        job['state'] = 'running'
        atomic_json(file, job)
        engine = Engine(cfg)
        action = job['action']
        if action == 'backup':
            result = {'generation': engine.backup()}
        elif action in ('verify', 'restore'):
            def before_restore():
                with job_lock(file):
                    checkpoint()
                    job['state'] = 'verifying' if action == 'verify' else 'restoring'
                    atomic_json(file, job)
            result = engine.restore(job['generation'], job['type'], action == 'verify', before_restore)
        else:
            with lock(cfg['runtime']):
                if action == 'delete':
                    for generation in engine.storage.generations():
                        engine.storage.delete_generation(generation)
                    engine.status(state='missing', last_success=None)
                    result = {'deleted': True}
                else:
                    # Probe both write and read paths; clean up only this unique test generation.
                    generation = utcnow().strftime('%Y%m%dT%H%M%SZ-') + uuid.uuid4().hex[:12]
                    key = 'generations/' + generation + '/config.000000'
                    try:
                        engine.storage.put(key, b'backupmanager-connection-test')
                        if engine.storage.get(key) != b'backupmanager-connection-test':
                            raise RuntimeError('Connection verification failed')
                        result = {'connected': True}
                    finally:
                        engine.storage.delete_generation(generation)
        job.update(state='completed', result=result)
    except Exception as error:
        # Only our deliberately sanitized errors are public; never serialize subprocess/http exceptions.
        message = str(error) if type(error) in (ValueError, RuntimeError, JobCancelled) else 'Operation failed; check configuration and service prerequisites'
        job.update(state='cancelled' if isinstance(error, JobCancelled) else 'failed', error=message)
    job['finished_at'] = utcnow().isoformat()
    atomic_json(file, job)
    # Keep job metadata bounded; never prune running jobs.
    for old in file.parent.glob('*.json'):
        if old.stat().st_mtime < (utcnow() - dt.timedelta(days=30)).timestamp():
            record = json.loads(old.read_text())
            if record.get('state') in ('completed', 'failed', 'cancelled'):
                old.unlink()
                old.with_suffix('.cancel').unlink(missing_ok=True)
                old.with_suffix('.lock').unlink(missing_ok=True)


def dispatch(action, cfg, data):
    if action == 'settings':
        settings = {key: cfg[key] for key in PUBLIC}
        settings.update(host_status(cfg))
        return {'settings': settings}
    if action == 'save':
        return save(cfg, data)
    if action == 'trust':
        return trust(cfg, data)
    if action == 'status':
        return {'status': safe_status(cfg)}
    if action == 'inventory':
        with lock(cfg['runtime']):
            storage = backend(cfg)
            from .engine import validate_manifest
            generations = []
            for generation in storage.inventory():
                manifest = validate_manifest(storage.get('generations/' + generation + '/manifest.json'), generation)
                generations.append({'id': generation, 'created_at': manifest['created_at'],
                                    'bytes': sum(c['size'] for chunks in manifest['artifacts'].values() for c in chunks)})
            return {'generations': generations, 'used_bytes': sum(g['bytes'] for g in generations)}
    if action == 'enqueue':
        return enqueue(cfg, data)
    if action in ('job', 'cancel'):
        file = job_path(cfg, data.get('id'))
        with job_lock(file):
            job = json.loads(file.read_text())
            if action == 'cancel':
                if job['state'] not in ('queued', 'running') or job['action'] not in ('verify', 'restore'):
                    raise ValueError('This job cannot be cancelled safely')
                file.with_suffix('.cancel').touch(mode=0o600)
        return {'job': job}
    raise ValueError('Unsupported operation')


def main():
    os.umask(0o077)
    try:
        action = sys.argv[1] if len(sys.argv) == 2 else ''
        # Commands that only read state must not wait for stdin from an interactive shell.
        data = {} if action in ('settings', 'status', 'inventory') else payload()
        result = dispatch(action, load(), data)
        print(json.dumps({'success': True} | result))
    except Exception as error:
        print('Backup Manager control failure: ' + type(error).__name__ + ': ' + str(error), file=sys.stderr)
        message = str(error) if type(error) in (ValueError, RuntimeError, JobCancelled) else 'Operation unavailable; check installation and configuration'
        print(json.dumps({'success': False, 'error': message}))
        return 1
    return 0
