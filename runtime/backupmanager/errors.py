"""Stable, credential-free causes shared by persisted jobs and backup status."""
import subprocess

MESSAGES = {
    'ssh_authentication': 'SSH authentication failed.',
    'ssh_host_verification': 'SSH host verification failed.',
    'rsync_failed': 'SSH file transfer failed.',
    'database_dump': 'Database dump failed.',
    'database_restore': 'Database restore failed.',
    's3_authentication': 'S3 authentication or access policy denied the request.',
    's3_api': 'S3 API request failed.',
    'storage_permission': 'Storage permission denied.',
    'connection_failed': 'Storage connection failed.',
    'cancelled': 'Operation cancelled by the operator.',
    'interrupted': 'Operation interrupted. Review the installation before retrying.',
    'service_terminated': 'Operation stopped by service or system termination.',
    'service_timeout': 'Operation exceeded the service time limit.',
    'cleanup_failed': 'Operation cleanup failed. Review the installation.',
    'invalid_configuration': 'Configuration is invalid. Review the settings and server logs.',
    'operation_failed': 'The operation failed. Check the server logs.',
    'configuration_changed': 'Settings changed after this job was queued; submit it again',
    'job_start_failed': 'The operation could not be started. Check the server logs.',
}

class Failure(RuntimeError):
    def __init__(self, code):
        self.code = code
        super().__init__(MESSAGES[code])


def failure_info(error):
    code = getattr(error, 'code', None)
    text = str(error).lower()
    if code not in MESSAGES:
        if isinstance(error, PermissionError): code = 'storage_permission'
        elif isinstance(error, (TimeoutError, subprocess.TimeoutExpired)): code = 'connection_failed'
        elif 'database dump failed' in text: code = 'database_dump'
        elif 'database restore failed' in text: code = 'database_restore'
        elif 'settings changed after' in text: code = 'configuration_changed'
        elif 'cancelled before' in text: code = 'cancelled'
        elif isinstance(error, ValueError): code = 'invalid_configuration'
        else: code = 'operation_failed'
    result = {'error_code': code, 'error': MESSAGES[code]}
    # RuntimeError/ValueError messages originate in our application. Other exception
    # strings may include HTTP bodies, credentials or subprocess arguments.
    if type(error) in (RuntimeError, ValueError):
        result['error_detail'] = ''.join(c for c in str(error) if c.isprintable() or c == '\n')[:2000]
    return result


def log_failure(error, context):
    """Log safe application diagnostics; never dump subprocess arguments or HTTP bodies."""
    import sys
    info = failure_info(error)
    detail = str(error) if type(error) in (RuntimeError, ValueError, OSError, PermissionError, FileNotFoundError) else type(error).__name__
    print('Backup Manager ' + context + ': ' + info['error_code'] + ': ' + info['error'] + ' (' + detail[:1000] + ')', file=sys.stderr)
