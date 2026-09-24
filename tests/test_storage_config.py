"""Storage mode boundaries, enrollment assignments and authoritative source paths."""
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'runtime'))
from backupmanager import client_helpers, config, control, phpconfig, storage
from test_runtime import FixtureEngine, MemoryStorage


class StorageConfigurationTests(unittest.TestCase):
    def test_destination_specific_validation(self):
        s3 = {'destination': 'aws_s3', 's3_bucket': 'fixture-bucket', 's3_prefix': 'installation',
              'ssh_host': '', 'ssh_user': '', 'ssh_path': '', 'ssh_port': 0,
              's3_endpoint': '', 's3_region': 'eu-west-1'}
        cfg = config.validate(s3)
        self.assertEqual(config.s3_endpoint(cfg), 'https://s3.eu-west-1.amazonaws.com')
        for region in config.AWS_REGIONS:
            config.validate(s3 | {'s3_region': region})
        with self.assertRaisesRegex(ValueError, 'AWS S3 region'):
            config.validate(s3 | {'s3_region': 'invented-1'})
        config.validate({'s3_endpoint': '', 's3_region': '', 's3_sse': 'unused'})
        with self.assertRaisesRegex(ValueError, 'SSH host'):
            config.validate({'credential_mode': 'manual'})
        for endpoint in ['', 'http://s3.example', 'https://user:pass@s3.example',
                         'https://s3.example/bucket', 'https://s3.example:99999']:
            with self.subTest(endpoint=endpoint), self.assertRaises(ValueError):
                config.validate(s3 | {'destination': 's3_compatible', 's3_endpoint': endpoint})
        config.validate(s3 | {'destination': 's3_compatible', 's3_endpoint': 'https://objects.example:9443', 's3_region': 'auto'})

    def test_inactive_payload_fields_preserve_stored_configuration(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'runtime.json'
            credentials = Path(directory) / 'credentials.json'
            config.atomic_json(credentials, {'access_key': 'stored-access', 'secret_key': 'stored-secret', 'session_token': 'stored-token'})
            original_credentials = credentials.read_bytes()
            base = dict(runtime=directory, ssh_host='storage.example', ssh_port=2222,
                        ssh_user='backupstore', ssh_path='/', client_id='BM-000007',
                        s3_endpoint='https://objects.example', s3_region='us-east-1',
                        s3_bucket='stored-bucket', s3_prefix='stored-prefix', s3_sse='AES256',
                        s3_credentials=str(credentials))
            for destination in ('ssh', 'aws_s3', 's3_compatible'):
                for mode in ('manual', 'managed'):
                    cfg = config.validate(base | {'destination': destination, 'credential_mode': mode})
                    inactive = ({key: '' for key in config.PUBLIC if key.startswith('s3_')}
                                | {'s3_access_key': 'unwanted-access', 's3_secret_key': 'unwanted-secret', 's3_session_token': 'unwanted-token'}) if destination == 'ssh' else {
                                    'ssh_host': '', 'ssh_port': 0, 'ssh_user': '', 'ssh_path': '', 'credential_mode': ''}
                    if destination == 'ssh' and mode == 'managed':
                        inactive.update(ssh_host='', ssh_port=0, ssh_user='', ssh_path='')
                    if destination == 'aws_s3':
                        inactive['s3_endpoint'] = 'https://unwanted.example'
                    with self.subTest(destination=destination, mode=mode), \
                         patch.object(control, 'CONFIG', str(target)), patch.object(control, 'apply_schedule'):
                        control.save(cfg, inactive | {'retention_days': 45})
                        saved = json.loads(target.read_text())
                        self.assertEqual(saved, config.remember_storage(cfg | {'retention_days': 45}))
                        self.assertEqual(credentials.read_bytes(), original_credentials)
                        self.assertFalse(list(Path(directory).glob('.s3-credentials-*')))

    def test_managed_assignment_and_mode_roundtrip(self):
        with tempfile.TemporaryDirectory() as directory:
            cfg = config.validate({'runtime': directory})
            connection = {'client_id': 'BM-000007', 'host': 'storage.example', 'port': 2222, 'user': 'backupstore', 'path': '/'}
            cfg = client_helpers.provider_config(cfg, connection)
            for changed in [{'path': '/var/lib/backupmanager-provider'}, {'user': 'root'}, {'host': ''}, {'port': True}]:
                with self.subTest(changed=changed), self.assertRaises(ValueError):
                    client_helpers.provider_config(cfg, connection | changed)
            target = Path(directory) / 'runtime.json'
            with patch.object(control, 'CONFIG', str(target)), patch.object(control, 'apply_schedule'):
                control.save(cfg, {'ssh_host': 'attacker.example', 'ssh_path': '/other'})
                saved = json.loads(target.read_text())
                self.assertEqual(saved['ssh_host'], 'storage.example')
                control.save(saved, {'credential_mode': 'manual', 'ssh_host': 'manual.example', 'ssh_user': 'archive', 'ssh_path': '/archive'})
                manual = json.loads(target.read_text())
                self.assertEqual(manual['ssh_host'], 'manual.example')
                control.save(manual, {'credential_mode': 'managed'})
                managed = json.loads(target.read_text())
                for key in control.SSH_FIELDS:
                    self.assertEqual(managed[key], cfg[key])
                self.assertEqual(target.stat().st_mode & 0o777, 0o600)
                control.save(managed, {'credential_mode': 'manual'})
                self.assertEqual(json.loads(target.read_text())['ssh_host'], 'manual.example')

    def test_destination_roundtrips_keep_distinct_credentials_and_settings(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / 'runtime.json'
            cfg = config.validate({'runtime': directory, 's3_credentials': str(root / 'initial.json'),
                'destination': 'ssh', 'credential_mode': 'manual', 'ssh_host': 'manual.example',
                'ssh_user': 'archive', 'ssh_path': '/archive', 'ssh_port': 2222})
            with patch.object(control, 'CONFIG', str(target)), patch.object(control, 'apply_schedule'):
                def save(fields):
                    nonlocal cfg
                    control.save(cfg, fields)
                    cfg = config.load(target)
                save({'destination': 's3_compatible', 's3_endpoint': 'https://objects.example',
                    's3_region': 'custom', 's3_bucket': 'compatible-bucket', 's3_prefix': 'compatible-prefix',
                    's3_access_key': 'compatible-access', 's3_secret_key': 'compatible-secret'})
                compatible = {k: cfg[k] for k in config.S3_FIELDS}
                save({'destination': 'aws_s3', 's3_region': 'eu-west-1', 's3_bucket': 'aws-bucket',
                    's3_prefix': 'aws-prefix', 's3_access_key': 'aws-access', 's3_secret_key': 'aws-secret'})
                aws = {k: cfg[k] for k in config.S3_FIELDS}
                save({'destination': 'ssh', 'credential_mode': 'managed'})
                self.assertEqual(cfg['ssh_host'], '')
                save({'credential_mode': 'manual'})
                self.assertEqual(cfg['ssh_host'], 'manual.example')
                self.assertEqual(cfg['ssh_port'], 2222)
                for destination, expected, secret in [('s3_compatible', compatible, 'compatible-secret'), ('aws_s3', aws, 'aws-secret')]:
                    save({'destination': destination, 's3_access_key': '', 's3_secret_key': '', 's3_session_token': ''})
                    self.assertEqual({k: cfg[k] for k in config.S3_FIELDS}, expected)
                    self.assertEqual(json.loads(Path(cfg['s3_credentials']).read_text())['secret_key'], secret)
                public = control.dispatch('settings', cfg, {})['settings']
                for profile in public['storage_profiles'].values():
                    self.assertNotIn('s3_credentials', profile)
                self.assertNotIn('compatible-secret', json.dumps(public))
                self.assertNotIn('aws-secret', json.dumps(public))

    def test_s3_endpoint_and_secret_boundaries(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'runtime.json'
            credentials = Path(directory) / 'credentials.json'
            config.atomic_json(credentials, {'access_key': 'test-access', 'secret_key': 'test-secret', 'session_token': 'test-token'})
            cfg = config.validate({'runtime': directory, 'destination': 'aws_s3', 's3_bucket': 'fixture-bucket', 's3_prefix': 'fixture', 's3_credentials': str(credentials)})
            with patch.object(control, 'CONFIG', str(target)), patch.object(control, 'apply_schedule'):
                result = control.save(cfg, {'s3_access_key': '', 's3_secret_key': '', 's3_session_token': '', 'ssh_host': ''})
                self.assertEqual(json.loads(target.read_text())['s3_credentials'], str(credentials))
                self.assertNotIn('test-secret', json.dumps(result))
                with self.assertRaisesRegex(ValueError, 'new credentials'):
                    control.save(cfg, {'s3_region': 'eu-west-1'})
                with self.assertRaisesRegex(ValueError, 'session token'):
                    control.save(cfg, {'s3_session_token': 'replacement'})
            backend = storage.S3(cfg | {'s3_endpoint': 'https://unused.example'})
            self.assertEqual(backend.cfg['s3_endpoint'], 'https://s3.us-east-1.amazonaws.com')

    def test_secret_values_stay_out_of_responses_and_errors(self):
        import io
        import urllib.error
        from unittest.mock import Mock
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            file = root / 'credentials.json'
            secrets = {'access_key': 'PRIVATE-ACCESS-SENTINEL', 'secret_key': 'PRIVATE-SECRET-SENTINEL', 'session_token': 'PRIVATE-TOKEN-SENTINEL'}
            config.atomic_json(file, secrets)
            cfg = config.validate({'runtime': directory, 'destination': 'aws_s3',
                's3_bucket': 'fixture-bucket', 's3_prefix': 'fixture', 's3_credentials': str(file)})
            target = root / 'runtime.json'
            config.atomic_json(target, cfg)
            with patch.object(control, 'CONFIG', str(target)), patch.object(control, 'apply_schedule'):
                response = control.save(cfg, {'s3_' + k: v for k, v in secrets.items()})
                saved = json.loads(target.read_text())
                public = control.dispatch('settings', saved, {})
                for secret in secrets.values():
                    self.assertNotIn(secret, json.dumps(response))
                    self.assertNotIn(secret, json.dumps(public))
                    self.assertNotIn(secret, target.read_text())
                for malformed in ['PRIVATE-SECRET-SENTINEL\n', 'PRIVATE-SECRET-SENTINEL\x00', 'PRIVATE-SECRET-SENTINEL\u2603', {'private': 'PRIVATE-SECRET-SENTINEL'}]:
                    before = target.read_bytes()
                    with self.assertRaisesRegex(ValueError, '^Invalid S3 credential format$'):
                        control.save(saved, {'s3_access_key': secrets['access_key'], 's3_secret_key': malformed})
                    self.assertEqual(target.read_bytes(), before)
                opener = Mock()
                opener.open.side_effect = urllib.error.HTTPError('https://s3.us-east-1.amazonaws.com', 403,
                    secrets['secret_key'], {}, io.BytesIO(secrets['session_token'].encode()))
                backend = storage.S3(saved, opener=opener)
                with self.assertRaises(RuntimeError) as failure:
                    backend.request('GET')
                for secret in secrets.values():
                    self.assertNotIn(secret, str(failure.exception))

    def test_credential_file_boundary(self):
        import os
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            file = root / 'credentials.json'
            cfg = config.validate({'destination': 'aws_s3', 's3_bucket': 'fixture-bucket', 's3_prefix': 'fixture', 's3_credentials': str(file)})
            config.atomic_json(file, {'access_key': 'fixture', 'secret_key': 'fixture'})
            link = root / 'link'
            link.symlink_to(file)
            fifo = root / 'pipe'
            os.mkfifo(fifo, 0o600)
            for path in (link, fifo, root):
                with self.subTest(path=path), self.assertRaises(ValueError):
                    storage.S3(cfg | {'s3_credentials': str(path)})
            file.chmod(0o644)
            with self.assertRaisesRegex(ValueError, 'private regular file'):
                storage.S3(cfg)
            file.chmod(0o600)
            for content in ('{invalid PRIVATE-SECRET-SENTINEL', '[]', 'x' * 65537):
                file.write_text(content)
                with self.assertRaises(ValueError) as failure:
                    storage.S3(cfg)
                self.assertNotIn('PRIVATE-SECRET-SENTINEL', str(failure.exception))

    def test_recovery_validates_before_activating_keys(self):
        import io
        from contextlib import redirect_stdout
        with tempfile.TemporaryDirectory() as directory:
            cfg = config.validate({'runtime': directory})
            connection = {'client_id': 'BM-000007', 'host': 'storage.example', 'port': 22,
                          'user': 'backupstore', 'path': '/', 'activate_recovery': True}
            for bad in [True, False]:
                data = connection | ({'path': '/real/provider/root'} if bad else {})
                with patch.object(client_helpers, 'load', return_value=cfg), \
                     patch.object(client_helpers, 'CONFIG', str(Path(directory) / 'runtime.json')), \
                     patch.object(client_helpers, 'payload', return_value=data), \
                     patch.object(client_helpers, 'activate') as activate, \
                     patch.object(client_helpers.sys, 'argv', ['backupmanager-apply-provider-config']), \
                     redirect_stdout(io.StringIO()):
                    result = client_helpers.main()
                    self.assertEqual(result, 1 if bad else 0)
                    self.assertEqual(activate.call_count, 0 if bad else 1)

    def test_external_source_and_override_mismatch_fail_before_backup(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            nc = root / 'nextcloud'
            (nc / 'config').mkdir(parents=True)
            data = root / 'external-data'
            data.mkdir()
            (data / 'fixture.txt').write_text('external source')
            runtime = root / 'runtime'
            (runtime / 'status').mkdir(parents=True)
            values = {'datadirectory': str(data), 'version': '34.0.0', 'dbtype': 'mysql', 'dbname': 'fixture'}
            (nc / 'config/config.php').write_text('<?php $CONFIG = ' + phpconfig.render(values) + ';')
            detected = phpconfig.data_directory(phpconfig.read_config(nc))
            self.assertEqual(detected, str(data))
            cfg = config.validate({'runtime': str(runtime), 'nc_path': str(nc), 'data_path': detected})
            backend = MemoryStorage()
            fixture = FixtureEngine(cfg, backend)
            fixture.backup()
            self.assertTrue(backend.inventory())
            (nc / 'config/storage.config.php').write_text("<?php $CONFIG = ['datadirectory' => '/mnt/changed-data'];")
            self.assertEqual(phpconfig.data_directory(phpconfig.read_config(nc)), '/mnt/changed-data')
            fixture.commands.clear()
            previous = dict(backend.objects)
            with self.assertRaisesRegex(ValueError, 'active Nextcloud datadirectory'):
                fixture.backup()
            self.assertEqual(fixture.commands, [])
            self.assertEqual(backend.objects, previous)
            with self.assertRaisesRegex(ValueError, 'active Nextcloud datadirectory'):
                fixture.restore(backend.inventory()[0], 'disaster')
            self.assertEqual(fixture.commands, [])
            for value in [None, '', 'relative', '/']:
                with self.assertRaises(ValueError):
                    phpconfig.data_directory({'datadirectory': value})
