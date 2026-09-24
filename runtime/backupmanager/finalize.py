"""Record unhandled exits without replacing a recorded root cause."""
import json
import fcntl
import re
from pathlib import Path
from .config import load, atomic_json
from .engine import utcnow
from .phpconfig import parse
from .errors import Failure, failure_info


def finalize(identity, result, exit_code='', exit_status=''):
    if identity != 'backup' and not re.fullmatch(r'[a-f0-9]{32}', identity):
        raise ValueError('Invalid job identity')
    # Type=exec may report SIGTERM/SIGINT as a clean service result.
    # A clean service stop is not a completed worker operation.
    if result == 'success' and exit_code not in ('killed', 'dumped'):
        return
    cfg = load()
    root = Path(cfg['runtime'])
    file = root / 'jobs' / (identity + '.json') if identity != 'backup' else None
    job = json.loads(file.read_text()) if file and file.exists() else None
    context = {'service_result': result, 'exit_code': exit_code, 'exit_status': exit_status}
    cancelled = bool(job and job.get('action') in ('restore', 'verify', 'test')
                     and job.get('state') in ('queued', 'running', 'cancelled')
                     and file.with_suffix('.cancel').exists())
    code = ('cancelled' if cancelled else 'service_timeout' if result == 'timeout'
            else 'service_terminated' if result in ('oom-kill', 'watchdog') or str(exit_status) in ('TERM', 'SIGTERM', '15')
            else 'interrupted' if result in ('signal', 'core-dump') or exit_code in ('killed', 'dumped') or str(exit_status) == '130'
            else 'operation_failed')
    cause = failure_info(Failure(code))
    # A completed/cancelled/failed worker record is authoritative. A late finalizer
    # may add service context, but cannot turn it into a different operation outcome.
    terminal = bool(job and job.get('state') in ('completed', 'failed', 'cancelled'))
    if job:
        if not terminal:
            job.update(state='cancelled' if cancelled else 'failed', finished_at=utcnow().isoformat())
            if not job.get('error'):
                job.update(cause)
        job['finalizer'] = context
        atomic_json(file, job)
        from .control import record_connection_test
        record_connection_test(cfg, job)
    # Connection tests, verification and queued cancellations are not backup failures.
    if job and (job.get('action') not in ('backup', 'restore') or cancelled or terminal):
        return
    with open(root / 'operation.lock', 'a') as stream:
        try:
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        state_file = root / 'status/state.json'
        state = json.loads(state_file.read_text()) if state_file.exists() else {}
        try:
            nc = parse((Path(cfg['nc_path']) / 'config/config.php').read_text())
            maintenance = bool(nc.get('maintenance', False))
        except Exception:
            maintenance = True
            context['maintenance_check_failed'] = True
        # Preserve a specific engine cause, including legacy records without codes.
        if not state.get('error'):
            state.update({k: job[k] for k in ('error', 'error_code', 'error_detail') if k in job} if job else cause)
        state.update(state='maintenance_required' if maintenance else 'failed',
                     finalizer=context, updated_at=utcnow().isoformat())
        atomic_json(state_file, state, 0o644)
        import sys
        print('Backup Manager finalizer: ' + state.get('error_code', 'legacy_error') + ': ' + state.get('error', '') + ' [service result: ' + result + ']', file=sys.stderr)
