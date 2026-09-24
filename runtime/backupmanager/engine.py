"""Recovery point creation and verified, fail-closed restoration."""
import contextlib
import datetime as dt
import fcntl
import gzip
import hashlib
import json
import os
import re
import shutil
import subprocess
import tarfile
import tempfile
import uuid
from pathlib import Path, PurePosixPath

from .errors import failure_info
from .config import atomic_json
from .phpconfig import parse, render, read_config, check_data_directory
from .storage import GENERATION, MAX_OBJECT, backend


def utcnow():
    return dt.datetime.now(dt.timezone.utc)


@contextlib.contextmanager
def lock(runtime):
    with open(Path(runtime) / 'operation.lock', 'a') as stream:
        try:
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('Another backup or restore operation is running') from None
        yield


def validate_manifest(data, generation):
    if len(data) > 16 * 1024 * 1024:
        raise ValueError('Manifest too large')
    value = json.loads(data)
    if value.get('version') != 1 or value.get('generation') != generation:
        raise ValueError('Invalid manifest version or identity')
    if set(value.get('artifacts', {})) != {'data', 'database', 'config'}:
        raise ValueError('Incomplete recovery point')
    created = dt.datetime.fromisoformat(value['created_at'])
    if created.tzinfo is None:
        raise ValueError('Manifest timestamp needs timezone')
    for name, chunks in value['artifacts'].items():
        if not isinstance(chunks, list) or not 1 <= len(chunks) <= 100000:
            raise ValueError('Invalid artifact chunks')
        for index, chunk in enumerate(chunks):
            if chunk.get('key') != f'generations/{generation}/{name}.{index:06d}':
                raise ValueError('Manifest object escapes generation')
            if type(chunk.get('size')) is not int or not 0 < chunk['size'] <= MAX_OBJECT:
                raise ValueError('Invalid chunk size')
            if not re.fullmatch(r'[a-f0-9]{64}', chunk.get('sha256', '')):
                raise ValueError('Invalid chunk checksum')
    return value


def validate_archive(file):
    """Reject special files and all archive links before touching the installation."""
    names, parent_names = {}, set()
    with tarfile.open(file, 'r:gz') as archive:
        for member in archive:
            path = PurePosixPath(member.name)
            if path.is_absolute() or '..' in path.parts or not path.parts or str(path) in names:
                raise ValueError('Unsafe or duplicate archive path')
            if not member.isfile() and not member.isdir():
                raise ValueError('Archive links and special files are not supported')
            if any(names.get(str(parent)) == 'file' for parent in path.parents) or (member.isfile() and str(path) in parent_names):
                raise ValueError('Conflicting archive paths')
            names[str(path)] = 'file' if member.isfile() else 'directory'
            parent_names.update(str(parent) for parent in path.parents)


def extract_archive(file, target):
    validate_archive(file)
    with tarfile.open(file, 'r:gz') as archive:
        for member in archive:
            dest = Path(target) / member.name
            dest.parent.mkdir(parents=True, exist_ok=True)
            if member.isdir():
                dest.mkdir(exist_ok=True)
            else:
                with archive.extractfile(member) as source, open(dest, 'xb') as out:
                    shutil.copyfileobj(source, out)
                os.chmod(dest, 0o640)


class Engine:
    def __init__(self, config, storage=None, run=subprocess.run):
        self.cfg, self._storage, self.run = config, storage, run
        self.root = Path(config['runtime'])

    @property
    def storage(self):
        if self._storage is None:
            self._storage = backend(self.cfg)
        return self._storage

    def command(self, args, **kwargs):
        result = self.run(args, capture_output=True, **kwargs)
        if result.returncode:
            # Command output can contain credentials or SQL data. Keep it private.
            raise RuntimeError('Operation failed: ' + Path(args[0]).name + ' (exit ' + str(result.returncode) + ')')
        return result.stdout

    def nc_config(self):
        return read_config(self.cfg['nc_path'])

    def occ(self, *args):
        return self.command(['runuser', '-u', self.cfg['nc_user'], '--', 'php',
                             str(Path(self.cfg['nc_path']) / 'occ'), *args], timeout=1800)

    def maintenance(self, enabled):
        self.occ('maintenance:mode', '--on' if enabled else '--off')
        if bool(self.nc_config().get('maintenance', False)) != enabled:
            raise RuntimeError('Effective maintenance configuration does not match the requested state')

    def db_command(self, executable):
        return ['runuser', '-u', self.cfg['nc_user'], '--', executable]

    def status(self, **values):
        file = self.root / 'status/state.json'
        previous = json.loads(file.read_text()) if file.exists() else {}
        atomic_json(file, previous | values | {'updated_at': utcnow().isoformat()}, 0o644)

    @contextlib.contextmanager
    def database_options(self, config):
        if config.get('dbtype', 'mysql') != 'mysql':
            raise ValueError('Only MySQL/MariaDB is supported')
        host = str(config.get('dbhost', 'localhost'))
        options = {'host': host, 'user': str(config.get('dbuser', '')), 'password': str(config.get('dbpassword', ''))}
        if ':' in host:
            host, port = host.split(':', 1)
            options['host'] = host
            if port.isdigit():
                options['port'] = port
            elif port.startswith('/'):
                options['socket'] = port
            else:
                raise ValueError('Unsupported database host format')
        name = str(config.get('dbname', ''))
        if not name or name.startswith('-') or '\x00' in name:
            raise ValueError('Invalid database name')
        with tempfile.NamedTemporaryFile(mode='w', dir=self.root, prefix='.db-') as file:
            file.write('[client]\n')
            for key, value in options.items():
                value = value.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\r', '\\r')
                file.write(key + '="' + value + '"\n')
            file.flush()
            if os.geteuid() == 0:
                import pwd
                account = pwd.getpwnam(self.cfg['nc_user'])
                os.chown(file.name, 0, account.pw_gid)
                os.chmod(file.name, 0o640)
            yield '--defaults-file=' + file.name, name

    def dump(self, config, target):
        with self.database_options(config) as (option, name), open(target, 'wb') as output:
            # stderr never reaches a web response or journal (SQL may contain secrets).
            with tempfile.TemporaryFile() as errors:
                process = subprocess.Popen(self.db_command('mysqldump') + [option, '--single-transaction', '--quick', '--lock-tables=false', '--', name], stdout=subprocess.PIPE, stderr=errors)
                try:
                    with gzip.GzipFile(fileobj=output, mode='wb') as compressed:
                        shutil.copyfileobj(process.stdout, compressed)
                finally:
                    process.stdout.close()
                if process.wait() != 0:
                    raise RuntimeError('Database dump failed')

    def archive_data(self, target):
        with open(target, 'wb') as output, tempfile.TemporaryFile() as errors:
            result = subprocess.run(['runuser', '-u', self.cfg['nc_user'], '--',
                                     '/usr/local/sbin/backupmanager-pack', self.cfg['data_path']],
                                    stdout=output, stderr=errors)
            if result.returncode:
                raise RuntimeError('Data snapshot failed; check readability, space, links and special files')

    def backup(self):
        with lock(self.root):
            self.status(state='running', last_attempt=utcnow().isoformat(), error='', error_code='', error_detail='', cleanup_error=None, finalizer=None)
            try:
                with tempfile.TemporaryDirectory(dir=self.root, prefix='stage-') as directory:
                    stage = Path(directory)
                    config = self.nc_config()
                    if config.get('objectstore') or config.get('objectstore_multibucket'):
                        raise ValueError('Primary object storage requires a separate object-storage backup')
                    if config.get('maintenance', False):
                        raise RuntimeError('Nextcloud is already in maintenance mode; operator action required')
                    check_data_directory(config, self.cfg['data_path'])
                    # Online backup: leave Nextcloud availability and maintenance state unchanged.
                    self.dump(config, stage / 'database')
                    self.archive_data(stage / 'data')
                    (stage / 'config').write_text('<?php\n$CONFIG = ' + render(config) + ';\n')
                    generation = utcnow().strftime('%Y%m%dT%H%M%SZ-') + uuid.uuid4().hex[:12]
                    manifest = {'version': 1, 'generation': generation, 'created_at': utcnow().isoformat(),
                                'source_id': self.cfg['source_id'], 'artifacts': {}}
                    for name in ('database', 'data', 'config'):
                        chunks = []
                        with open(stage / name, 'rb') as stream:
                            while chunk := stream.read(MAX_OBJECT):
                                key = f'generations/{generation}/{name}.{len(chunks):06d}'
                                self.storage.put(key, chunk)
                                chunks.append({'key': key, 'size': len(chunk), 'sha256': hashlib.sha256(chunk).hexdigest()})
                        manifest['artifacts'][name] = chunks
                    encoded = json.dumps(manifest, sort_keys=True).encode()
                    validate_manifest(encoded, generation)
                    self.storage.put(f'generations/{generation}/manifest.json', encoded)
                    self.status(state='ok', last_success=utcnow().isoformat(), generation=generation, error='', error_code='', error_detail='', cleanup_error=None, finalizer=None)
                    self.retention(generation)
                    return generation
            except Exception as error:
                self.status(state='failed', **failure_info(error))
                raise

    def retention(self, newest):
        cutoff = utcnow() - dt.timedelta(days=self.cfg['retention_days'])
        for generation in self.storage.inventory():
            if generation == newest:
                continue
            manifest = validate_manifest(self.storage.get(f'generations/{generation}/manifest.json'), generation)
            if dt.datetime.fromisoformat(manifest['created_at']) < cutoff:
                self.storage.delete_generation(generation)
        # Incomplete uploads are never inventory entries; remove only after a full retention period.
        for generation in self.storage.generations():
            created = dt.datetime.strptime(generation[:16], '%Y%m%dT%H%M%SZ').replace(tzinfo=dt.timezone.utc)
            if generation != newest and created < cutoff and generation not in self.storage.inventory():
                self.storage.delete_generation(generation)

    def download(self, generation, stage):
        if not GENERATION.fullmatch(generation):
            raise ValueError('Invalid generation')
        manifest = validate_manifest(self.storage.get(f'generations/{generation}/manifest.json'), generation)
        for name, chunks in manifest['artifacts'].items():
            with open(stage / name, 'wb') as out:
                for chunk in chunks:
                    data = self.storage.get(chunk['key'])
                    if len(data) != chunk['size'] or hashlib.sha256(data).hexdigest() != chunk['sha256']:
                        raise ValueError('Recovery point integrity check failed')
                    out.write(data)
        parse((stage / 'config').read_text())
        validate_archive(stage / 'data')
        with gzip.open(stage / 'database') as stream:
            while stream.read(1024 * 1024):
                pass
        return manifest

    def readable_stage(self, stage, unpacked):
        import pwd
        account = pwd.getpwnam(self.cfg['nc_user'])
        from itertools import chain
        for entry in chain([stage, unpacked], unpacked.rglob('*')):
            os.chown(entry, 0, account.pw_gid)
            os.chmod(entry, 0o750 if entry.is_dir() else 0o640)

    def current_app_config(self):
        data = json.loads(self.occ('config:list', 'backupstatus', '--private', '--output=json'))
        apps = data.get('apps', {})
        values = apps.get('backupstatus', {})
        if not isinstance(values, dict) or any(not isinstance(k, str) or not isinstance(v, (str, int, bool)) for k, v in values.items()):
            raise ValueError('Cannot preserve current Backup Manager application configuration')
        return {'apps': {'backupstatus': values}}

    def import_app_config(self, data):
        import pwd
        account = pwd.getpwnam(self.cfg['nc_user'])
        with tempfile.TemporaryDirectory(dir=self.root, prefix='nc-config-') as directory:
            os.chown(directory, 0, account.pw_gid)
            os.chmod(directory, 0o750)
            file = Path(directory) / 'app.json'
            atomic_json(file, data, 0o640)
            os.chown(file, 0, account.pw_gid)
            self.occ('config:import', str(file))

    def restore(self, generation, kind='complete', verify=False, checkpoint=lambda: None):
        if kind not in ('data', 'database', 'complete', 'disaster'):
            raise ValueError('Invalid restore type')
        with lock(self.root), tempfile.TemporaryDirectory(dir=self.root, prefix='restore-') as directory:
            stage = Path(directory)
            self.download(generation, stage)
            checkpoint()  # Cancellation is safe only before live changes.
            local = self.nc_config()
            restored_config = parse((stage / 'config').read_text())
            if local.get('version') != restored_config.get('version'):
                raise ValueError('Install the same Nextcloud version as the recovery point before restoring')
            check_data_directory(local, self.cfg['data_path'])
            with tarfile.open(stage / 'data', 'r:gz') as archive:
                required_space = sum(member.size for member in archive if member.isfile())
            if shutil.disk_usage(stage).free < required_space:
                raise RuntimeError('Insufficient staging space for verified data extraction')
            with self.database_options(local) as (option, database):
                self.command(self.db_command('mysql') + [option, '--batch', '--skip-column-names', '--execute=SELECT 1', '--', database], timeout=30)
            if verify:
                return {'verified': generation}
            app_config = self.current_app_config() if kind in ('database', 'complete', 'disaster') else None
            if local.get('maintenance', False):
                raise RuntimeError('Maintenance already enabled; inspect the previous operation before retrying')
            self.maintenance(True)
            self.status(state='restoring', error='', error_code='', error_detail='', cleanup_error=None, finalizer=None)
            try:
                if kind == 'disaster':
                    restored = restored_config
                    # Keep the new host's DB connection and data location; restore instance secrets as data.
                    for key in ('dbhost', 'dbname', 'dbuser', 'dbpassword', 'dbtype', 'datadirectory', 'trusted_domains', 'overwrite.cli.url'):
                        if key in local:
                            restored[key] = local[key]
                    restored['maintenance'] = True
                    self.command(['runuser', '-u', self.cfg['nc_user'], '--',
                                  '/usr/local/sbin/backupmanager-config-install',
                                  str(Path(self.cfg['nc_path']) / 'config/config.php')],
                                 input=('<?php\n$CONFIG = ' + render(restored) + ';\n').encode(), timeout=60)
                if kind in ('database', 'complete', 'disaster'):
                    with self.database_options(local) as (option, database), gzip.open(stage / 'database') as source, tempfile.TemporaryFile() as errors:
                        process = subprocess.Popen(self.db_command('mysql') + [option, '--binary-mode', '--', database], stdin=subprocess.PIPE, stdout=errors, stderr=errors)
                        try:
                            shutil.copyfileobj(source, process.stdin)
                            process.stdin.close()
                        except Exception:
                            process.kill()
                            process.wait()
                            raise
                        if process.wait() != 0:
                            raise RuntimeError('Database restore failed; maintenance remains enabled')
                if kind in ('data', 'complete', 'disaster'):
                    unpacked = stage / 'unpacked'
                    unpacked.mkdir()
                    extract_archive(stage / 'data', unpacked)
                    target = Path(self.cfg['data_path'])
                    if target.is_symlink() or target.resolve() == Path('/') or not target.is_dir():
                        raise ValueError('Unsafe restore target')
                    self.readable_stage(stage, unpacked)
                    self.command(['runuser', '-u', self.cfg['nc_user'], '--', 'rsync', '-rlt', '--delete',
                                  '--chmod=Du=rwx,Dg=rx,Do=,Fu=rw,Fg=r,Fo=',
                                  str(unpacked) + '/', str(target) + '/'], timeout=86400)
                if app_config is not None:
                    self.import_app_config(app_config)
                self.occ('maintenance:repair')
                self.occ('maintenance:data-fingerprint')
                self.maintenance(False)
                self.status(state='restored', restored_generation=generation, error='', error_code='', error_detail='', cleanup_error=None, finalizer=None)
                return {'restored': generation}
            except Exception as error:
                self.status(state='maintenance_required', **failure_info(error))
                raise
