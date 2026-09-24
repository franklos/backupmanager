"""Read-only deployment checks: installed CLI files must also be the served provider."""
from pathlib import Path
import re
import os
import subprocess
import fnmatch

PUBLIC = '/opt/backupmanager-provider/public'
SOCKET = '/run/php/backupmanager-provider.sock'


def pool_sections(file):
    """Read pool directives per section; never combine identities from two pools."""
    sections = []
    current = None
    for line in file.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith((';', '#')):
            continue
        if line.startswith('[') and line.endswith(']'):
            current = {}
            sections.append(current)
        elif current is not None and '=' in line:
            key, value = line.split('=', 1)
            current[key.strip()] = value.split(';', 1)[0].strip().strip('"\'')
    return sections


def provider_pools(root):
    return [(file, section) for file in (root / 'etc/php').glob('*/fpm/pool.d/*.conf')
            for section in pool_sections(file) if section.get('listen') == SOCKET]


def detect_fpm(root=Path('/'), version=None, active_versions=None):
    """Discover Debian/Ubuntu FPM installations, independently of CLI PHP.

    active_versions is injectable for isolated fixtures. Never execute a fixture binary.
    Existing provider socket ownership wins; ambiguity requires explicit selection.
    """
    root = Path(root)
    candidates = {}
    for directory in sorted((root / 'etc/php').glob('*/fpm')):
        number = directory.parent.name
        if not re.fullmatch(r'[0-9]+\.[0-9]+', number):
            continue
        binary = root / ('usr/sbin/php-fpm' + number)
        service = 'php' + number + '-fpm.service'
        units = [root / base / service for base in ('usr/lib/systemd/system', 'lib/systemd/system', 'etc/systemd/system')]
        config = directory / 'php-fpm.conf'
        if not (binary.is_file() and os.access(binary, os.X_OK) and config.is_file()
                and (directory / 'pool.d').is_dir() and any(unit.is_file() for unit in units)):
            continue
        # A pool in an unreferenced directory is not an installed/served pool.
        expected = '/etc/php/' + number + '/fpm/pool.d/backupmanager-provider.conf'
        includes = re.findall(r'(?m)^\s*include\s*=\s*([^;\n]+)', config.read_text())
        if not any(fnmatch.fnmatchcase(expected, item.strip().strip('"\'')) for item in includes):
            continue
        candidates[number] = dict(version=number, binary='/usr/sbin/php-fpm' + number,
                                  service=service, pool=expected, socket=SOCKET)
    if not candidates:
        raise ValueError('No usable PHP-FPM installation: require a versioned /usr/sbin/php-fpm binary, systemd unit and /etc/php/<version>/fpm/php-fpm.conf including pool.d/*.conf. No path was guessed.')
    pools = provider_pools(root)
    if len(pools) > 1:
        raise ValueError('Multiple PHP-FPM pools claim ' + SOCKET + '; resolve duplicate listeners before proceeding')
    owner = pools[0][0].parents[2].name if pools else None
    if owner and owner not in candidates:
        raise ValueError('Existing provider pool belongs to an unusable PHP-FPM installation: ' + owner)
    if owner:
        candidates[owner]['pool'] = '/' + str(pools[0][0].relative_to(root))
    if version is not None:
        if version not in candidates:
            raise ValueError('Requested PHP-FPM version is not usable: ' + version)
        if owner and owner != version:
            raise ValueError('Existing provider pool belongs to PHP-FPM ' + owner + '; refusing selection of ' + version)
        return candidates[version]
    if owner:
        return candidates[owner]
    if active_versions is None:
        active_versions = []
        if root == Path('/'):
            for number, candidate in candidates.items():
                try:
                    result = subprocess.run(['systemctl', 'is-active', '--quiet', candidate['service']],
                                            capture_output=True, timeout=5)
                    if result.returncode == 0:
                        active_versions.append(number)
                except (OSError, subprocess.TimeoutExpired):
                    pass
    active = sorted(set(active_versions) & candidates.keys())
    if len(active) == 1:
        return candidates[active[0]]
    if len(candidates) == 1:
        return next(iter(candidates.values()))
    raise ValueError('Ambiguous PHP-FPM installations (' + ', '.join(candidates) +
                     '); choose --php-fpm-version after checking the intended FPM service. CLI PHP is not used for selection.')


def web_errors(root=Path('/'), version=None):
    root = Path(root)
    errors = []
    sites = []
    for directory, pattern, server in [('etc/apache2/sites-enabled', '*.conf', 'Apache'),
                                       ('etc/nginx/sites-enabled', '*', 'nginx'),
                                       ('etc/nginx/conf.d', '*.conf', 'nginx')]:
        for file in (root / directory).glob(pattern):
            if not file.is_file():
                continue
            text = re.sub(r'(?m)^\s*#.*$', '', file.read_text())
            if 'backupmanager-provider' not in text:
                continue
            sites.append(file)
            if server == 'Apache':
                if re.search(r'(?mi)^\s*AuthType\s+Basic\b', text):
                    errors.append(f'{file}: redundant Apache Basic Auth creates a second admin login; preserve HTTPS and use the provider administrator session for /admin/')
                modules = '\n'.join(module.read_text() for module in (root / 'etc/apache2/mods-enabled').glob('*.load'))
                if not re.search(r'(?m)^\s*LoadModule\s+proxy_fcgi_module\s+', modules):
                    errors.append(f'{file}: required Apache proxy_fcgi module is not enabled; enable proxy and proxy_fcgi, validate and reload Apache. Otherwise PHP source can be returned as HTTP 200')
            directive = r'(?mi)^\s*DocumentRoot\s+[\"\']?([^\s\"\']+)' if server == 'Apache' else r'(?m)^\s*root\s+([^;\s]+)'
            paths = re.findall(directive, text)
            if not paths or any(path != PUBLIC for path in paths):
                errors.append(f'{file}: provider document root must be {PUBLIC}; installed CLI code is not necessarily served code')
            if SOCKET not in text:
                errors.append(f'{file}: route provider PHP to unix:{SOCKET}, not the default www-data pool')
    if not sites:
        errors.append('No enabled provider HTTPS site found; configure the supplied Apache or nginx example')
    try:
        selected = detect_fpm(root, version)
    except ValueError as error:
        errors.append(str(error))
        selected = None
    pools = provider_pools(root)
    if not pools:
        errors.append(f'No dedicated bmprovider PHP-FPM pool listens on {SOCKET}; use php-fpm.conf.example')
    for file, section in pools:
        if any(section.get(key) != 'bmprovider' for key in ('user', 'group')):
            errors.append(f'{file}: provider FPM user and group must both be bmprovider')
        if selected:
            config = root / ('etc/php/' + selected['version'] + '/fpm/php-fpm.conf')
            includes = re.findall(r'(?m)^\s*include\s*=\s*([^;\n]+)', config.read_text())
            actual = '/' + str(file.relative_to(root))
            if not any(fnmatch.fnmatchcase(actual, item.strip().strip('"\'')) for item in includes):
                errors.append(f'{file}: provider pool is not included by selected PHP-FPM configuration')
    return errors
