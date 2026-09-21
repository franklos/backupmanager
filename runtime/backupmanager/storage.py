"""Storage implementations sharing one bounded object interface."""
import abc
import datetime as dt
import hashlib
import hmac
import json
import re
import shlex
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path

GENERATION = re.compile(r'^\d{8}T\d{6}Z-[a-f0-9]{12}$')
OBJECT = re.compile(r'^generations/(\d{8}T\d{6}Z-[a-f0-9]{12})/(manifest\.json|(?:data|database|config)\.\d{6})$')
MAX_OBJECT = 64 * 1024 * 1024


def check_key(key):
    if not OBJECT.fullmatch(key):
        raise ValueError('Invalid recovery point object')
    return key


class Storage(abc.ABC):
    @abc.abstractmethod
    def put(self, key, data): ...

    @abc.abstractmethod
    def get(self, key): ...

    @abc.abstractmethod
    def keys(self): ...

    @abc.abstractmethod
    def delete_generation(self, generation): ...

    def generations(self):
        return {key.split('/')[1] for key in self.keys()}

    def inventory(self):
        result = []
        for key in self.keys():
            if key.endswith('/manifest.json') and OBJECT.fullmatch(key):
                result.append(key.split('/')[1])
        return sorted(set(result), reverse=True)


class SSH(Storage):
    def __init__(self, config, run=subprocess.run):
        self.cfg, self.run = config, run

    def command(self, read=False):
        cfg = self.cfg
        return ['ssh', '-F', '/dev/null', '-o', 'ForwardAgent=no', '-o', 'ClearAllForwardings=yes', '-i', cfg['ssh_read_key'] if read else cfg['ssh_key'],
                '-p', str(cfg['ssh_port']), '-o', 'BatchMode=yes',
                '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile=' + cfg['known_hosts'],
                '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'UpdateHostKeys=no',
                '-o', 'ConnectTimeout=15']

    def remote(self, suffix):
        return self.cfg['ssh_user'] + '@' + self.cfg['ssh_host'] + ':' + self.cfg['ssh_path'].rstrip('/') + '/' + suffix

    def rsync(self, args, read=False, timeout=3600):
        command = ['rsync', '--timeout=120', '-e', shlex.join(self.command(read))] + args
        # Network clients never run as root on the installed system.
        import os
        if os.geteuid() == 0:
            command = ['runuser', '-u', 'backupmgr', '--'] + command
        result = self.run(command, capture_output=True, timeout=timeout)
        if result.returncode:
            import sys
            diagnostic = result.stderr.decode('utf-8', errors='replace')
            print('Backup Manager SSH failure: ' + ''.join(c for c in diagnostic if c.isprintable() or c == '\n')[:2000], file=sys.stderr)
            raise RuntimeError('SSH transfer failed; verify host trust, permissions and connectivity')
        return result.stdout.decode('utf-8', errors='strict')

    def temp(self):
        import os
        import pwd
        directory = tempfile.TemporaryDirectory(dir=Path(self.cfg['runtime']) / 'transfer')
        if os.geteuid() == 0:
            account = pwd.getpwnam('backupmgr')
            os.chown(directory.name, account.pw_uid, account.pw_gid)
        return directory

    def put(self, key, data):
        check_key(key)
        if len(data) > MAX_OBJECT:
            raise ValueError('Object exceeds chunk size')
        import os
        import pwd
        with self.temp() as directory:
            file = Path(directory) / key
            file.parent.mkdir(parents=True)
            file.write_bytes(data)
            if os.geteuid() == 0:
                account = pwd.getpwnam('backupmgr')
                for entry in Path(directory).rglob('*'):
                    os.chown(entry, account.pw_uid, account.pw_gid)
            self.rsync(['-r', '--relative', directory + '/./' + key, self.remote('')])

    def get(self, key):
        check_key(key)
        with self.temp() as directory:
            self.rsync(['--max-size=' + str(MAX_OBJECT), self.remote(key), directory + '/'], read=True)
            file = Path(directory) / key.split('/')[-1]
            if file.is_symlink() or not file.is_file() or file.stat().st_size > MAX_OBJECT:
                raise RuntimeError('Missing or oversized remote object')
            import os
            with os.fdopen(os.open(file, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
                data = stream.read(MAX_OBJECT + 1)
                if len(data) > MAX_OBJECT:
                    raise RuntimeError('Oversized remote object')
                return data

    def keys(self):
        output = self.rsync(['-r', '--list-only', '--out-format=%n', self.remote('')], read=True)
        # rsync list-only output ends with the relative pathname; filenames are fixed ASCII.
        return [match[0] for line in output.splitlines()
                if (match := re.search(r'generations/\d{8}T\d{6}Z-[a-f0-9]{12}/(?:manifest\.json|(?:data|database|config)\.\d{6})$', line))]

    def delete_generation(self, generation):
        if not GENERATION.fullmatch(generation):
            raise ValueError('Invalid generation')
        with self.temp() as directory:
            self.rsync(['-r', '--delete', directory + '/', self.remote('generations/' + generation + '/')])


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never forward signed credentials to another origin.


class S3(Storage):
    """HTTPS path-style S3 with SigV4, pagination and bounded single-part objects.

    Chunked artifacts avoid SDK dependencies and multipart limits. Credentials stay
    in a root-only local file and never appear in arguments or returned errors.
    """
    def __init__(self, config, opener=None, clock=None):
        self.cfg = config
        self.opener = opener or urllib.request.build_opener(NoRedirect())
        self.clock = clock or (lambda: dt.datetime.now(dt.timezone.utc))
        file = Path(config['s3_credentials'])
        if file.is_symlink() or file.stat().st_mode & 0o077:
            raise ValueError('S3 credentials must be a private regular file')
        self.credentials = json.loads(file.read_text())
        for key in ('access_key', 'secret_key'):
            if not isinstance(self.credentials.get(key), str) or not self.credentials[key]:
                raise ValueError('S3 credentials incomplete')
        if any('\n' in str(v) or '\r' in str(v) for v in self.credentials.values()):
            raise ValueError('Invalid S3 credentials')

    def request(self, method, key='', data=b'', query=None):
        quote = lambda value: urllib.parse.quote(str(value), safe='-_.~')
        cfg = self.cfg
        path = '/' + quote(cfg['s3_bucket'])
        if key:
            path += '/' + '/'.join(quote(p) for p in key.split('/'))
        query_text = '&'.join(quote(k) + '=' + quote(v) for k, v in sorted((query or {}).items()))
        now = self.clock()
        stamp, date = now.strftime('%Y%m%dT%H%M%SZ'), now.strftime('%Y%m%d')
        digest = hashlib.sha256(data).hexdigest()
        headers = {'host': urllib.parse.urlsplit(cfg['s3_endpoint']).netloc,
                   'x-amz-date': stamp, 'x-amz-content-sha256': digest}
        if self.credentials.get('session_token'):
            headers['x-amz-security-token'] = self.credentials['session_token']
        if method == 'PUT' and cfg['s3_sse']:
            headers['x-amz-server-side-encryption'] = cfg['s3_sse']
        names = ';'.join(sorted(headers))
        canonical = '\n'.join((method, path, query_text,
                              ''.join(k + ':' + headers[k].strip() + '\n' for k in sorted(headers)), names, digest))
        scope = date + '/' + cfg['s3_region'] + '/s3/aws4_request'
        to_sign = 'AWS4-HMAC-SHA256\n' + stamp + '\n' + scope + '\n' + hashlib.sha256(canonical.encode()).hexdigest()
        signing = ('AWS4' + self.credentials['secret_key']).encode()
        for part in (date, cfg['s3_region'], 's3', 'aws4_request'):
            signing = hmac.new(signing, part.encode(), hashlib.sha256).digest()
        signature = hmac.new(signing, to_sign.encode(), hashlib.sha256).hexdigest()
        headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' + self.credentials['access_key'] + '/' + scope + ', SignedHeaders=' + names + ', Signature=' + signature
        url = cfg['s3_endpoint'].rstrip('/') + path + ('?' + query_text if query_text else '')
        for attempt in range(4):
            try:
                request = urllib.request.Request(url, data=data if method == 'PUT' else None, headers=headers, method=method)
                with self.opener.open(request, timeout=120) as response:
                    result = response.read(MAX_OBJECT + 1)
                    if len(result) > MAX_OBJECT:
                        raise RuntimeError('S3 object exceeds allowed size')
                    return result
            except urllib.error.HTTPError as error:
                if error.code not in (429, 500, 502, 503, 504) or attempt == 3:
                    raise RuntimeError('S3 request failed (HTTP ' + str(error.code) + '); check endpoint, region, policy and credentials') from None
            except (urllib.error.URLError, TimeoutError):
                if attempt == 3:
                    raise RuntimeError('S3 connection failed') from None
            time.sleep(2 ** attempt)

    def object_key(self, key):
        return self.cfg['s3_prefix'] + '/' + check_key(key)

    def put(self, key, data):
        if len(data) > MAX_OBJECT:
            raise ValueError('Object exceeds chunk size')
        self.request('PUT', self.object_key(key), data)

    def get(self, key):
        return self.request('GET', self.object_key(key))

    def keys(self):
        prefix = self.cfg['s3_prefix'] + '/'
        query = {'list-type': '2', 'prefix': prefix + 'generations/'}
        result, seen = [], set()
        while True:
            root = ET.fromstring(self.request('GET', query=query))
            def values(name):
                return [e.text or '' for e in root.iter() if e.tag.split('}')[-1] == name]
            for key in values('Key'):
                if key.startswith(prefix) and OBJECT.fullmatch(key[len(prefix):]):
                    result.append(key[len(prefix):])
            if values('IsTruncated') != ['true']:
                return result
            tokens = values('NextContinuationToken')
            if not tokens or tokens[0] in seen:
                raise RuntimeError('Invalid S3 pagination')
            seen.add(tokens[0])
            query['continuation-token'] = tokens[0]

    def versioned(self):
        if not hasattr(self, '_versioned'):
            root = ET.fromstring(self.request('GET', query={'versioning': ''}))
            self._versioned = any(e.tag.split('}')[-1] == 'Status' and e.text in ('Enabled', 'Suspended') for e in root.iter())
        return self._versioned

    def versions(self, generation=None):
        prefix = self.cfg['s3_prefix'] + '/'
        query = {'versions': '', 'prefix': prefix + 'generations/' + ((generation + '/') if generation else '')}
        found, seen = [], set()
        while True:
            root = ET.fromstring(self.request('GET', query=query))
            def child(element, name):
                return next((e.text or '' for e in element if e.tag.split('}')[-1] == name), '')
            for element in root:
                if element.tag.split('}')[-1] not in ('Version', 'DeleteMarker'):
                    continue
                key, version = child(element, 'Key'), child(element, 'VersionId')
                if key.startswith(prefix) and OBJECT.fullmatch(key[len(prefix):]) and version:
                    relative = key[len(prefix):]
                    if generation is None or relative.split('/')[1] == generation:
                        found.append((relative, version))
            if child(root, 'IsTruncated') != 'true':
                return found
            markers = (child(root, 'NextKeyMarker'), child(root, 'NextVersionIdMarker'))
            if not markers[0] or markers in seen:
                raise RuntimeError('Invalid S3 version pagination')
            seen.add(markers)
            query.update({'key-marker': markers[0], 'version-id-marker': markers[1]})

    def generations(self):
        if self.versioned():
            return {key.split('/')[1] for key, _ in self.versions()}
        return super().generations()

    def delete_generation(self, generation):
        if not GENERATION.fullmatch(generation):
            raise ValueError('Invalid generation')
        if self.versioned():
            objects = self.versions(generation)
            objects.sort(key=lambda pair: not pair[0].endswith('/manifest.json'))
            for key, version in objects:
                self.request('DELETE', self.object_key(key), query={'versionId': version})
        else:
            keys = [key for key in self.keys() if key.startswith('generations/' + generation + '/')]
            keys.sort(key=lambda key: not key.endswith('/manifest.json'))
            for key in keys:
                self.request('DELETE', self.object_key(key))


def backend(config):
    return SSH(config) if config['destination'] == 'ssh' else S3(config)
