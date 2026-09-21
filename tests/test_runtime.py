import base64
import datetime as dt
import gzip
import hashlib
import io
import json
import os
import struct
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO / 'runtime'))
from backupmanager import client_helpers, config, control, engine, phpconfig, provider_helpers, storage


class MemoryStorage(storage.Storage):
    def __init__(self):
        self.objects = {}
    def put(self, key, data):
        self.objects[storage.check_key(key)] = data
    def get(self, key):
        return self.objects[storage.check_key(key)]
    def keys(self):
        return list(self.objects)
    def delete_generation(self, generation):
        if not storage.GENERATION.fullmatch(generation):
            raise ValueError('bad generation')
        self.objects = {k: v for k, v in self.objects.items() if k.split('/')[1] != generation}


class FixtureEngine(engine.Engine):
    def __init__(self, cfg, backend):
        super().__init__(cfg, backend)
        self.commands = []
        self.fail_restore = False
    def occ(self, *args):
        self.commands.append(args)
        if args and args[0] == 'maintenance:mode':
            file = Path(self.cfg['nc_path']) / 'config/config.php'
            values = phpconfig.parse(file.read_text())
            values['maintenance'] = args[1] == '--on'
            file.write_text('<?php $CONFIG = ' + phpconfig.render(values) + ';')
        if self.fail_restore and args == ('maintenance:repair',):
            raise RuntimeError('Synthetic repair failure')
        return b'{}'
    def readable_stage(self, stage, unpacked):
        pass
    def archive_data(self, target):
        from backupmanager.archive import pack_data
        with open(target, 'wb') as output:
            pack_data(self.cfg['data_path'], output)
    def dump(self, cfg, target):
        with gzip.open(target, 'wb') as stream:
            stream.write(b'-- synthetic SQL fixture\n')
    def command(self, args, **kwargs):
        self.commands.append(tuple(args))
        return b''


class ControlCliTests(unittest.TestCase):
    def test_payloadless_status_does_not_read_stdin(self):
        cfg = config.validate({})
        with patch.object(control, 'load', return_value=cfg), patch.object(control, 'dispatch', return_value={'status': {}}), patch.object(control, 'payload', side_effect=AssertionError('stdin was read')), patch.object(control.sys, 'argv', ['backupmanager-control', 'status']):
            self.assertEqual(control.main(), 0)


class ConfigTests(unittest.TestCase):
    def test_php_literal_numeric_semantics(self):
        self.assertEqual(phpconfig.parse('<?php $CONFIG = ARRAY("mode" => 0770, "hex" => 0xff, "float" => 1.5, "flag" => TRUE);'),
                         {'mode': 0o770, 'hex': 255, 'float': 1.5, 'flag': True})
        with self.assertRaises(ValueError):
            phpconfig.parse('<?php $CONFIG = ["number" => 1e9999];')

    def test_php_roundtrip_without_execution(self):
        values = {'dbtype': 'mysql', 'enabled': True, 'password': "fixture'\\value", 'array': {0: 'one', 'nested': None}}
        self.assertEqual(phpconfig.parse('<?php $CONFIG = ' + phpconfig.render(values) + ';'), values)

    def test_reject_executable_php(self):
        for text in ["<?php $CONFIG = include '/tmp/file';", "<?php $CONFIG = ['x' => system('id')];",
                     '<?php $CONFIG = ["x" => "$secret"];', '<?php $CONFIG = []; system("id");',
                     '<?php $CONFIG = ["x" => 1, "x" => 2];', '<?php $CONFIG = ["x" => __DIR__];']:
            with self.subTest(text=text), self.assertRaises(ValueError):
                phpconfig.parse(text)

    def test_legacy_shell_is_never_executed(self):
        with tempfile.TemporaryDirectory() as tmp:
            file = Path(tmp) / 'old.conf'
            file.write_text('NC_PATH=/var/www/nextcloud\nBACKUP_PORT=2222\n')
            self.assertEqual(config.legacy(file)['ssh_port'], 2222)
            for content in ['BACKUP_HOST=$(touch /tmp/never)\n', 'BACKUP_HOST=example;id\n', 'source /tmp/anything\n']:
                file.write_text(content)
                with self.assertRaises(ValueError):
                    config.legacy(file)

    def test_settings_validation(self):
        for values in [{'ssh_host': '-oProxyCommand=bad'}, {'ssh_path': '/../other'}, {'nc_path': '/'},
                       {'days': 'Mon\nOther'}, {'s3_endpoint': 'http://example.com'}, {'ssh_port': True},
                       {'destination': 'aws_s3', 's3_bucket': 'fixture-bucket', 's3_prefix': '../other'}]:
            with self.subTest(values=values), self.assertRaises((ValueError, TypeError)):
                config.validate(values)

    def test_no_secrets_in_public_settings(self):
        result = control.dispatch('settings', config.validate({}), {})
        self.assertFalse({'s3_credentials', 'ssh_key', 'secret_key', 'access_key'} & set(result['settings']))


class LifecycleTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        nc = root / 'nextcloud'
        (nc / 'config').mkdir(parents=True)
        data = nc / 'data'
        data.mkdir()
        (data / 'fixture.txt').write_text('fixture content')
        (nc / 'config/config.php').write_text('<?php $CONFIG = ' + phpconfig.render({'dbtype': 'mysql', 'dbhost': 'localhost', 'dbname': 'fixture', 'dbuser': 'fixture', 'dbpassword': 'synthetic-only', 'datadirectory': str(data), 'version': '34.0.0'}) + ';')
        runtime = root / 'runtime'
        runtime.mkdir()
        self.cfg = config.validate({'runtime': str(runtime), 'nc_path': str(nc), 'data_path': str(data)})
        self.store = MemoryStorage()
        self.engine = FixtureEngine(self.cfg, self.store)

    def test_backup_verify_and_data_restore(self):
        generation = self.engine.backup()
        self.assertEqual(self.store.inventory(), [generation])
        self.assertEqual(list(self.store.objects)[-1], 'generations/' + generation + '/manifest.json')
        self.assertEqual(self.engine.restore(generation, verify=True), {'verified': generation})
        self.engine.restore(generation, 'data')
        self.assertIn(('maintenance:data-fingerprint',), self.engine.commands)
        self.assertEqual(self.engine.commands[-1], ('maintenance:mode', '--off'))

    def test_manifest_is_not_published_on_failed_upload(self):
        original = self.store.put
        def put(key, data):
            if '/data.' in key:
                raise RuntimeError('Synthetic upload failure')
            original(key, data)
        self.store.put = put
        with self.assertRaises(RuntimeError):
            self.engine.backup()
        self.assertEqual(self.store.inventory(), [])
        self.assertEqual(control.safe_status(self.cfg)['state'], 'failed')

    def test_corruption_stops_before_maintenance_or_mutation(self):
        generation = self.engine.backup()
        self.engine.commands.clear()
        self.store.objects['generations/' + generation + '/data.000000'] = b'corrupted'
        with self.assertRaises(ValueError):
            self.engine.restore(generation, 'data')
        self.assertEqual(self.engine.commands, [])

    def test_failed_restore_keeps_maintenance_enabled(self):
        generation = self.engine.backup()
        self.engine.commands.clear()
        self.engine.fail_restore = True
        with self.assertRaises(RuntimeError):
            self.engine.restore(generation, 'data')
        self.assertIn(('maintenance:mode', '--on'), self.engine.commands)
        self.assertNotIn(('maintenance:mode', '--off'), self.engine.commands)
        self.assertEqual(control.safe_status(self.cfg)['state'], 'maintenance_required')

    def test_cancel_checkpoint_before_live_changes(self):
        generation = self.engine.backup()
        self.engine.commands.clear()
        def cancel():
            raise RuntimeError('cancelled')
        with self.assertRaises(RuntimeError):
            self.engine.restore(generation, 'data', checkpoint=cancel)
        self.assertEqual(self.engine.commands, [])

    def test_retention_keeps_latest_and_unrelated_objects(self):
        generation = self.engine.backup()
        old = '20000101T000000Z-abcdef123456'
        manifest = json.loads(self.store.get('generations/' + generation + '/manifest.json'))
        manifest['generation'] = old
        manifest['created_at'] = '2000-01-01T00:00:00+00:00'
        for chunks in manifest['artifacts'].values():
            for chunk in chunks:
                original = chunk['key']
                chunk['key'] = original.replace(generation, old)
                self.store.put(chunk['key'], self.store.get(original))
        self.store.put('generations/' + old + '/manifest.json', json.dumps(manifest).encode())
        self.engine.retention(generation)
        self.assertEqual(self.store.inventory(), [generation])

    def test_job_cancellation_and_interrupted_restore_status(self):
        from backupmanager import finalize
        identity = 'a' * 32
        file = Path(self.cfg['runtime']) / 'jobs' / (identity + '.json')
        config.atomic_json(file, {'id': identity, 'action': 'restore', 'state': 'running'})
        control.dispatch('cancel', self.cfg, {'id': identity})
        self.assertTrue(file.with_suffix('.cancel').exists())
        config.atomic_json(file, {'id': identity, 'action': 'restore', 'state': 'restoring'})
        with self.assertRaises(ValueError):
            control.dispatch('cancel', self.cfg, {'id': identity})
        self.engine.occ('maintenance:mode', '--on')
        with patch.object(finalize, 'load', return_value=self.cfg):
            finalize.finalize(identity, 'signal')
        self.assertEqual(control.safe_status(self.cfg)['state'], 'maintenance_required')
        self.assertEqual(json.loads(file.read_text())['state'], 'failed')
        self.assertTrue(self.engine.nc_config()['maintenance'])

    def test_primary_object_storage_is_not_misreported_as_complete(self):
        file = Path(self.cfg['nc_path']) / 'config/config.php'
        values = phpconfig.parse(file.read_text())
        values['objectstore'] = {'class': 'FixtureObjectStore'}
        file.write_text('<?php $CONFIG = ' + phpconfig.render(values) + ';')
        with self.assertRaises(ValueError):
            self.engine.backup()
        self.assertEqual(self.store.inventory(), [])

    def test_lock_and_stale_detection(self):
        with engine.lock(self.cfg['runtime']):
            with self.assertRaises(RuntimeError):
                with engine.lock(self.cfg['runtime']):
                    pass
        self.engine.status(state='ok', last_success='2000-01-01T00:00:00+00:00')
        self.assertEqual(control.safe_status(self.cfg)['state'], 'stale')

    def test_archive_traversal_and_links_rejected(self):
        for name, kind in [('../escape', tarfile.REGTYPE), ('/absolute', tarfile.REGTYPE), ('link', tarfile.SYMTYPE), ('hard', tarfile.LNKTYPE), ('device', tarfile.CHRTYPE)]:
            file = Path(self.tmp.name) / 'bad.tar.gz'
            with tarfile.open(file, 'w:gz') as archive:
                member = tarfile.TarInfo(name)
                member.type = kind
                member.linkname = '/etc/passwd'
                archive.addfile(member)
            with self.subTest(name=name), self.assertRaises(ValueError):
                engine.validate_archive(file)

    def test_manifest_cannot_reference_other_generation(self):
        generation = self.engine.backup()
        manifest = json.loads(self.store.get('generations/' + generation + '/manifest.json'))
        manifest['artifacts']['config'][0]['key'] = '../../secret'
        with self.assertRaises(ValueError):
            engine.validate_manifest(json.dumps(manifest).encode(), generation)


class S3Tests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        file = Path(self.tmp.name) / 'fixture-credentials.json'
        config.atomic_json(file, {'access_key': 'fixture-access', 'secret_key': 'fixture-secret', 'session_token': 'fixture-session'})
        self.cfg = config.validate({'destination': 's3_compatible', 's3_endpoint': 'https://storage.example.test',
                                    's3_bucket': 'fixture-bucket', 's3_prefix': 'fixture/client', 's3_credentials': str(file)})
        self.requests = []
        self.responses = []
        owner = self
        class Opener:
            def open(self, request, timeout):
                owner.requests.append(request)
                return io.BytesIO(owner.responses.pop(0))
        self.backend = storage.S3(self.cfg, opener=Opener(), clock=lambda: dt.datetime(2026, 1, 2, tzinfo=dt.timezone.utc))

    def test_signed_put_get_with_session_credentials(self):
        key = 'generations/20260102T000000Z-abcdef123456/config.000000'
        self.responses = [b'', b'fixture']
        self.backend.put(key, b'fixture')
        self.assertEqual(self.backend.get(key), b'fixture')
        request = self.requests[0]
        self.assertIn('/fixture-bucket/fixture/client/generations/', request.full_url)
        self.assertIn('Credential=fixture-access/20260102/us-east-1/s3/aws4_request', request.get_header('Authorization'))
        self.assertEqual(request.get_header('X-amz-content-sha256'), hashlib.sha256(b'fixture').hexdigest())
        self.assertEqual(request.get_header('X-amz-security-token'), 'fixture-session')
        self.assertNotIn('fixture-secret', request.get_header('Authorization'))

    def test_pagination_and_prefix_scoped_delete(self):
        gen = '20260102T000000Z-abcdef123456'
        prefix = 'fixture/client/generations/' + gen + '/'
        first = ('<ListBucketResult><Contents><Key>' + prefix + 'manifest.json</Key></Contents><IsTruncated>true</IsTruncated><NextContinuationToken>a+/=</NextContinuationToken></ListBucketResult>').encode()
        second = ('<ListBucketResult><Contents><Key>' + prefix + 'config.000000</Key></Contents><Contents><Key>other/client/secret</Key></Contents><IsTruncated>false</IsTruncated></ListBucketResult>').encode()
        self.responses = [b'<VersioningConfiguration/>', first, second, b'', b'']
        self.backend.delete_generation(gen)
        self.assertIn('continuation-token=a%2B%2F%3D', self.requests[2].full_url)
        self.assertTrue(self.requests[3].full_url.endswith('manifest.json'))
        self.assertEqual([r.get_method() for r in self.requests], ['GET', 'GET', 'GET', 'DELETE', 'DELETE'])

    def test_versioned_deletion_removes_versions_and_markers_only_in_scope(self):
        generation = '20260102T000000Z-abcdef123456'
        key = 'fixture/client/generations/' + generation + '/manifest.json'
        self.responses = [b'<VersioningConfiguration><Status>Enabled</Status></VersioningConfiguration>',
            ('<ListVersionsResult><Version><Key>' + key + '</Key><VersionId>v+1</VersionId></Version>'
             '<DeleteMarker><Key>' + key + '</Key><VersionId>delete-marker</VersionId></DeleteMarker>'
             '<Version><Key>other-client/secret</Key><VersionId>outside</VersionId></Version>'
             '<IsTruncated>false</IsTruncated></ListVersionsResult>').encode(), b'', b'']
        self.backend.delete_generation(generation)
        deletes = [request.full_url for request in self.requests if request.get_method() == 'DELETE']
        self.assertEqual(len(deletes), 2)
        self.assertTrue(any('versionId=v%2B1' in url for url in deletes))
        self.assertTrue(all('outside' not in url and '/fixture/client/' in url for url in deletes))

    def test_reject_insecure_credentials_and_cross_prefix_keys(self):
        Path(self.cfg['s3_credentials']).chmod(0o644)
        with self.assertRaises(ValueError):
            storage.S3(self.cfg)
        with self.assertRaises(ValueError):
            self.backend.get('../another-installation/secret')


class ProviderKeyTests(unittest.TestCase):
    def public(self, byte):
        return 'ssh-ed25519 ' + base64.b64encode(struct.pack('>I', 11) + b'ssh-ed25519' + struct.pack('>I', 32) + bytes([byte]) * 32).decode()

    def test_enrollment_accepts_newline_terminated_public_sidecar(self):
        with tempfile.TemporaryDirectory() as tmp:
            private = Path(tmp) / 'id_ed25519'
            public = Path(str(private) + '.pub')
            public.write_text(self.public(7) + '\n')
            self.assertEqual(client_helpers.public_key(private), self.public(7))

    def test_wire_format_and_injected_options(self):
        self.assertEqual(provider_helpers.key(self.public(1)), self.public(1))
        for value in ['command="id" ' + self.public(1), self.public(1) + '\n' + self.public(2), 'ssh-ed25519 AAAA']:
            with self.assertRaises(ValueError):
                provider_helpers.key(value)

    def test_atomic_pair_install_revoke_and_traversal(self):
        with tempfile.TemporaryDirectory() as tmp:
            root, account = Path(tmp) / 'storage', Path(tmp) / 'account'
            root.mkdir()
            (account / '.ssh').mkdir(parents=True)
            import pwd
            with patch.object(provider_helpers, 'ROOT', root), patch.object(provider_helpers, 'ACCOUNT', account), patch.object(provider_helpers.pwd, 'getpwnam', return_value=pwd.getpwuid(os.getuid())):
                provider_helpers.install({'client_id': 'BM-000001', 'write_key': self.public(1), 'read_key': self.public(2)})
                text = (account / '.ssh/authorized_keys').read_text()
                self.assertIn('rrsync -wo ', text)
                self.assertIn('rrsync -ro ', text)
                self.assertEqual(text.count('restrict,command='), 2)
                with self.assertRaises(ValueError):
                    provider_helpers.install({'client_id': 'BM-000003', 'write_key': self.public(1), 'read_key': self.public(2)})
                provider_helpers.remove('BM-000001')
                self.assertNotIn('bm-client=', (account / '.ssh/authorized_keys').read_text())
                self.assertTrue((root / 'BM-000001').is_dir())
                with self.assertRaises(ValueError):
                    provider_helpers.target('../escape')
                (root / 'BM-000002').symlink_to(account)
                with self.assertRaises(ValueError):
                    provider_helpers.remove('BM-000002', True)


class InstallationTests(unittest.TestCase):
    def test_staged_install_upgrade_and_client_removal_preserve_provider(self):
        with tempfile.TemporaryDirectory() as tmp:
            for component in ('client', 'provider'):
                subprocess.run([sys.executable, str(REPO / 'installer/install.py'), component, '--destdir', tmp], check=True, capture_output=True)
            root = Path(tmp)
            cfg = root / 'etc/backupmanager/runtime.json'
            settings = json.loads(cfg.read_text())
            settings['retention_days'] = 71
            config.atomic_json(cfg, settings)
            subprocess.run([sys.executable, str(REPO / 'installer/install.py'), 'client', '--destdir', tmp], check=True, capture_output=True)
            self.assertEqual(json.loads(cfg.read_text())['retention_days'], 71)
            self.assertTrue((root / 'usr/local/bin/backup-config.sh').exists())
            self.assertTrue((root / 'usr/local/sbin/backupmanager-worker').exists())
            self.assertTrue((root / 'usr/local/sbin/backupmanager-install-authorized-keys').exists())
            subprocess.run([sys.executable, str(REPO / 'installer/uninstall.py'), '--destdir', tmp], check=True, capture_output=True)
            self.assertTrue(cfg.exists())
            self.assertTrue((root / 'opt/backupmanager-provider/public/index.php').exists())
            self.assertTrue((root / 'usr/local/lib/backupmanager/backupmanager/provider_helpers.py').exists())
            self.assertFalse((root / 'usr/local/sbin/backupmanager-control').exists())

    def test_schema_is_non_destructive_and_foreign_keys_ordered(self):
        text = (REPO / 'provider/sql/schema.sql').read_text()
        self.assertNotIn('DROP TABLE', text)
        self.assertNotIn('TRUNCATE', text)
        self.assertLess(text.index('CREATE TABLE IF NOT EXISTS `provider_requests`'), text.index('CREATE TABLE IF NOT EXISTS `provider_events`'))
        self.assertIn('CREATE TABLE IF NOT EXISTS deletion_requests', text)


if __name__ == '__main__':
    unittest.main()


class FinalizerTests(unittest.TestCase):
    def test_conflicting_job_preserves_active_state(self):
        from backupmanager import finalize
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'status').mkdir()
            (root / 'jobs').mkdir()
            state = {'state': 'running'}
            config.atomic_json(root / 'status/state.json', state)
            identity = 'a' * 32
            config.atomic_json(root / 'jobs' / (identity + '.json'), {'state': 'queued'})
            with patch.object(finalize, 'load', return_value={'runtime': directory, 'nc_path': directory}), engine.lock(root):
                finalize.finalize(identity, 'exit-code')
            self.assertEqual(json.loads((root / 'status/state.json').read_text()), state)
            self.assertEqual(json.loads((root / 'jobs' / (identity + '.json')).read_text())['state'], 'failed')


class CredentialSaveTests(unittest.TestCase):
    def test_atomic_credentials_and_schedule_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            cfg = dict(config.DEFAULTS, runtime=directory, s3_credentials=str(root / 'old.json'))
            config.atomic_json(root / 'old.json', {'access_key': 'fixture', 'secret_key': 'fixture'})
            target = root / 'runtime.json'
            config.atomic_json(target, cfg)
            fields = {'s3_access_key': 'fixture-new', 's3_secret_key': 'fixture-secret'}
            with patch.object(control, 'CONFIG', str(target)), patch.object(control, 'apply_schedule', side_effect=RuntimeError('fixture')):
                with self.assertRaises(RuntimeError):
                    control.save(cfg, fields)
            self.assertEqual(json.loads(target.read_text()), cfg)
            self.assertFalse(list(root.glob('.s3-credentials-*')))
            with patch.object(control, 'CONFIG', str(target)), patch.object(control, 'apply_schedule'):
                result = control.save(cfg, fields)
            saved = json.loads(target.read_text())
            credentials = Path(saved['s3_credentials'])
            self.assertEqual(credentials.stat().st_mode & 0o777, 0o600)
            self.assertEqual(json.loads(credentials.read_text())['access_key'], 'fixture-new')
            self.assertNotIn('fixture-secret', json.dumps(result))
