#!/usr/bin/env python3
"""Explicit deployment or isolated file staging. Never consumes repository secrets."""
import argparse
import grp
import json
import os
import pwd
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SOURCE / 'runtime'))
from backupmanager.config import DEFAULTS, atomic_json, legacy, validate
from backupmanager.phpconfig import parse


def run(args):
    subprocess.run(args, check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('component', choices=['client', 'provider'])
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--destdir', type=Path, help='Stage files only; no users, services, keys or database operations')
    mode.add_argument('--apply', action='store_true', help='Deploy to this machine (operator action)')
    parser.add_argument('--nextcloud-root', default=DEFAULTS['nc_path'])
    parser.add_argument('--migrate', action='store_true', help='Explicitly migrate configured provider database')
    args = parser.parse_args()
    if args.migrate and (not args.apply or args.component != 'provider'):
        parser.error('--migrate requires provider --apply')
    if args.apply and os.geteuid() != 0:
        parser.error('--apply requires root')
    root = args.destdir.resolve() if args.destdir else Path('/')
    if args.destdir and root == Path('/'):
        parser.error('Staging at / is forbidden')
    if not Path(args.nextcloud_root).is_absolute() or '..' in Path(args.nextcloud_root).parts:
        parser.error('Nextcloud root must be an absolute path')

    def path(name):
        return root / name.lstrip('/')

    installed = []
    def copy(source, destination, mode=0o644, preserve=False):
        target = path(destination)
        target.parent.mkdir(parents=True, exist_ok=True)
        if target.is_symlink():
            raise ValueError('Refusing symlink installation target')
        if not preserve or not target.exists():
            fd, temporary = tempfile.mkstemp(dir=target.parent)
            os.close(fd)
            shutil.copyfile(source, temporary)
            os.chmod(temporary, mode)
            os.replace(temporary, target)
        installed.append(destination)

    def text(destination, content, mode=0o644, preserve=False):
        target = path(destination)
        target.parent.mkdir(parents=True, exist_ok=True)
        if target.is_symlink():
            raise ValueError('Refusing symlink installation target')
        if not preserve or not target.exists():
            fd, temporary = tempfile.mkstemp(dir=target.parent)
            with os.fdopen(fd, 'w') as stream:
                stream.write(content)
                os.fchmod(stream.fileno(), mode)
            os.replace(temporary, target)
        installed.append(destination)

    if args.apply:
        dependencies = ['python3', 'php', 'sudo', 'systemctl', 'visudo', 'ssh-keygen', 'rsync']
        dependencies += ['mysqldump', 'mysql'] if args.component == 'client' else ['rrsync']
        missing = [name for name in dependencies if not shutil.which(name)]
        if missing:
            raise RuntimeError('Install prerequisites yourself before deployment: ' + ', '.join(missing))
        if args.component == 'provider':
            for rule in Path('/etc/sudoers.d').glob('*'):
                if rule.is_file() and rule.name != 'backupmanager-provider':
                    content = rule.read_text(errors='replace')
                    if 'www-data' in content and any(name in content for name in ('backupmanager-install-authorized-keys', 'backupmanager-remove-authorized-key', 'backupmanager-remove-storage', 'backupmanager-install-restore-key')):
                        raise RuntimeError('Review obsolete provider sudo grants before deployment: ' + str(rule))
        if args.component == 'client' and not (Path(args.nextcloud_root) / 'occ').is_file():
            raise ValueError('Nextcloud installation not found')
        users = [('backupmgr', '/var/lib/backupmanager', '/usr/sbin/nologin')] if args.component == 'client' else [
            ('bmprovider', '/var/lib/backupmanager-provider-api', '/usr/sbin/nologin'),
            ('backupstore', '/var/lib/backupmanager-provider-account', '/bin/sh')]
        for user, home, shell in users:
            try:
                pwd.getpwnam(user)
            except KeyError:
                run(['useradd', '--system', '--user-group', '--home-dir', home, '--shell', shell, user])
        if args.component == 'provider':
            run(['usermod', '--home', '/var/lib/backupmanager-provider-account', '--shell', '/bin/sh', 'backupstore'])

    for source in (SOURCE / 'runtime/backupmanager').glob('*.py'):
        copy(source, '/usr/local/lib/backupmanager/backupmanager/' + source.name)

    if args.component == 'client':
        for source in (SOURCE / 'app/backupstatus').rglob('*'):
            if source.is_file():
                copy(source, args.nextcloud_root + '/apps/backupstatus/' + str(source.relative_to(SOURCE / 'app/backupstatus')))
        for category in ('bin', 'sbin'):
            for source in (SOURCE / 'service' / category).iterdir():
                if source.is_file():
                    copy(source, '/usr/local/' + category + '/' + source.name, 0o755)
        for source in (SOURCE / 'service/systemd').iterdir():
            copy(source, '/etc/systemd/system/' + source.name)
        copy(SOURCE / 'installer/uninstall.py', '/usr/local/sbin/backupmanager-uninstall-now', 0o755)
        settings = path('/etc/backupmanager/runtime.json')
        if not settings.exists():
            old = path('/etc/backupmanager/backupmanager.conf')
            cfg = legacy(old) if old.exists() else dict(DEFAULTS)
            old_schedule = path('/etc/systemd/system/backupmanager.timer.d/schedule.conf')
            if old_schedule.exists():
                calendars = [line.split('=', 1)[1] for line in old_schedule.read_text().splitlines() if line.startswith('OnCalendar=') and line != 'OnCalendar=']
                if len(calendars) != 1:
                    raise ValueError('Review the existing timer schedule before migration')
                match = re.fullmatch(r'([A-Za-z,]+) \*-\*-\* (\d{2}:\d{2}):00(?: ([A-Za-z0-9_+/.-]+))?', calendars[0])
                if not match:
                    raise ValueError('Unsupported existing timer expression; no configuration was overwritten')
                cfg.update(days=match[1], time=match[2])
                if match[3]:
                    cfg['timezone'] = match[3]
            cfg['nc_path'] = args.nextcloud_root
            if args.apply:
                nc = parse((Path(args.nextcloud_root) / 'config/config.php').read_text())
                cfg['data_path'] = str(nc.get('datadirectory', cfg['data_path']))
            atomic_json(settings, validate(cfg))
        cfg = validate(json.loads(settings.read_text()))
        dirs = {'/etc/backupmanager': 0o750, cfg['runtime']: 0o711,
                cfg['runtime'] + '/status': 0o755, cfg['runtime'] + '/jobs': 0o700,
                cfg['runtime'] + '/transfer': 0o711, cfg['runtime'] + '/.ssh': 0o700}
        for directory, mode in dirs.items():
            path(directory).mkdir(parents=True, exist_ok=True)
            path(directory).chmod(mode)
        sudo = ''.join('www-data ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-control ' + action + '\n'
                       for action in ['settings', 'save', 'trust', 'status', 'inventory', 'enqueue', 'job', 'cancel'])
        sudo += ''.join('www-data ALL=(root) NOPASSWD: /usr/local/sbin/' + name + ' ""\n' for name in [
            'backupmanager-request-info', 'backupmanager-recovery-request-info',
            'backupmanager-activate-recovery-key', 'backupmanager-apply-provider-config'])
        sudo += 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-uninstall-client keep-data, /usr/local/sbin/backupmanager-uninstall-client purge\n'
        text('/etc/sudoers.d/backupmanager', sudo, 0o440)
        # Preserve existing schedule on upgrade; otherwise use the authoritative configuration.
        text('/etc/systemd/system/backupmanager.timer.d/schedule.conf', '[Timer]\nOnCalendar=\nOnCalendar=' + cfg['days'] + ' *-*-* ' + cfg['time'] + ':00 ' + cfg['timezone'] + '\n')
        if args.apply:
            account = pwd.getpwnam('backupmgr')
            if 'backupmgr' in grp.getgrnam('www-data').gr_mem:
                run(['gpasswd', '-d', 'backupmgr', 'www-data'])
            # Revoke legacy runtime directory ownership before root orchestration.
            for directory in dirs:
                os.chown(directory, 0, 0)
            os.chown('/etc/backupmanager', 0, grp.getgrnam('www-data').gr_gid)
            for key_name in ('ssh_key', 'ssh_read_key'):
                key = Path(cfg[key_name])
                if key.is_symlink():
                    raise ValueError('Symlink private key refused')
                if not key.exists():
                    run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key)])
                os.chown(key, account.pw_uid, account.pw_gid)
                key.chmod(0o600)
            # Root owns the directory and host-trust file; backupmgr can read keys, not change trust.
            os.chown(path(cfg['runtime'] + '/.ssh'), 0, account.pw_gid)
            path(cfg['runtime'] + '/.ssh').chmod(0o750)
            run(['visudo', '-cf', '/etc/sudoers.d/backupmanager'])
            run(['systemctl', 'daemon-reload'])
            run(['runuser', '-u', 'www-data', '--', 'php', args.nextcloud_root + '/occ', 'app:enable', 'backupstatus'])
            print('Client installed. Configure storage and pin SSH host trust before enabling the timer in settings.')
    else:
        for directory in ('src', 'public', 'bin', 'sql'):
            for source in (SOURCE / 'provider' / directory).rglob('*'):
                if source.is_file():
                    copy(source, '/opt/backupmanager-provider/' + str(source.relative_to(SOURCE / 'provider')))
        copy(SOURCE / 'provider/config/config.php.example', '/etc/backupmanager-provider/config.php', 0o640, preserve=True)
        for source in (SOURCE / 'provider/system').iterdir():
            copy(source, '/usr/local/sbin/' + source.name, 0o755)
        for directory, mode in [('/etc/backupmanager-provider', 0o750), ('/var/lib/backupmanager-provider', 0o755),
                                ('/var/lib/backupmanager-provider-account', 0o755),
                                ('/var/lib/backupmanager-provider-account/.ssh', 0o755),
                                ('/var/lib/backupmanager-provider-api', 0o700),
                                ('/var/lib/backupmanager-provider-api/limits', 0o700)]:
            path(directory).mkdir(parents=True, exist_ok=True)
            path(directory).chmod(mode)
        sudo = 'bmprovider ALL=(root) NOPASSWD: /usr/local/sbin/backupmanager-install-authorized-keys ""\n'
        sudo += ''.join('bmprovider ALL=(root) NOPASSWD: /usr/local/sbin/' + name + ' BM-*\n' for name in [
            'backupmanager-remove-authorized-key', 'backupmanager-remove-storage', 'backupmanager-storage-usage'])
        text('/etc/sudoers.d/backupmanager-provider', sudo, 0o440)
        copy(SOURCE / 'provider/config/nginx.conf.example', '/opt/backupmanager-provider/nginx.conf.example')
        copy(SOURCE / 'provider/config/php-fpm.conf.example', '/opt/backupmanager-provider/php-fpm.conf.example')
        if args.apply:
            account = pwd.getpwnam('bmprovider')
            for directory in ('/var/lib/backupmanager-provider', '/var/lib/backupmanager-provider-account', '/var/lib/backupmanager-provider-account/.ssh'):
                os.chown(directory, 0, 0)
            for directory in ('/var/lib/backupmanager-provider-api', '/var/lib/backupmanager-provider-api/limits'):
                os.chown(directory, account.pw_uid, account.pw_gid)
            for file in ('/etc/backupmanager-provider', '/etc/backupmanager-provider/config.php'):
                os.chown(file, 0, account.pw_gid)
            run(['visudo', '-cf', '/etc/sudoers.d/backupmanager-provider'])
            if args.migrate:
                run(['runuser', '-u', 'bmprovider', '--', 'php', '/opt/backupmanager-provider/bin/migrate.php'])
            print('Provider files installed. Complete dedicated FPM/HTTPS, database and administrator setup in docs/operations.md.')
    for obsolete in ('/usr/local/sbin/backupmanager-provision-storage', '/usr/local/sbin/backupmanager-install-restore-key'):
        path(obsolete).unlink(missing_ok=True)
    if args.component == 'client':
        path(args.nextcloud_root + '/apps/backupstatus/templates/unavailable.php').unlink(missing_ok=True)
    manifest = path('/usr/local/share/backupmanager/' + args.component + '-files.json')
    manifest.parent.mkdir(parents=True, exist_ok=True)
    previous = json.loads(manifest.read_text()) if manifest.exists() else []
    atomic_json(manifest, sorted(set(previous + installed)), 0o644)
    print('Staged ' + args.component + ' files.' if args.destdir else 'Deployment finished.')


if __name__ == '__main__':
    main()
