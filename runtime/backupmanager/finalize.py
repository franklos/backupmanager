"""Systemd failure finalizer; never disables maintenance on an interrupted job."""
import json
import fcntl
import re
from pathlib import Path
from .config import load, atomic_json
from .engine import utcnow
from .phpconfig import parse


def finalize(identity, result):
    if result == 'success':
        return
    cfg = load()
    root = Path(cfg['runtime'])
    error = 'Operation interrupted. Review the installation before retrying.'
    # A rejected overlapping job must not overwrite the active operation's state.
    with open(root / 'operation.lock', 'a') as stream:
        try:
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            pass
        else:
            try:
                nc = parse((Path(cfg['nc_path']) / 'config/config.php').read_text())
                maintenance = bool(nc.get('maintenance', False))
            except Exception:
                maintenance = True
            state_file = root / 'status/state.json'
            state = json.loads(state_file.read_text()) if state_file.exists() else {}
            state.update(state='maintenance_required' if maintenance else 'failed',
                         error=error, updated_at=utcnow().isoformat())
            atomic_json(state_file, state, 0o644)
    if identity != 'backup':
        if not re.fullmatch(r'[a-f0-9]{32}', identity):
            raise ValueError('Invalid job identity')
        file = root / 'jobs' / (identity + '.json')
        if file.exists():
            job = json.loads(file.read_text())
            job.update(state='failed', error=error, finished_at=utcnow().isoformat())
            atomic_json(file, job)
