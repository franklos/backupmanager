import contextlib
import io
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from test_runtime import config, control, storage


class HostVerificationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(root / 'key')], check=True)
        self.key = ' '.join((root / 'key.pub').read_text().split()[:2])
        self.pin = root / 'known_hosts'
        self.cfg = config.validate({'runtime': str(root), 'known_hosts': str(self.pin), 'ssh_host': 'storage.example', 'ssh_port': 2222})
        self.pin.write_text('[storage.example]:2222 ' + self.key + '\n')

    def test_existing_pin_and_hashed_pin_return_key_and_fingerprint(self):
        for hashed in (False, True):
            if hashed:
                subprocess.run(['ssh-keygen', '-H', '-f', str(self.pin)], capture_output=True, check=True)
            result = control.dispatch('settings', self.cfg, {})['settings']
            self.assertTrue(result['host_trusted'])
            self.assertEqual(result['host_key'], self.key)
            self.assertTrue(result['host_fingerprint'].startswith('SHA256:'))

    def test_missing_wrong_endpoint_and_invalid_pin(self):
        for content in ('', 'other.example ' + self.key, '[storage.example]:2222 ssh-ed25519 AAAA'):
            self.pin.write_text(content + '\n')
            self.assertFalse(control.host_trusted(self.cfg))
        self.pin.unlink()
        self.assertFalse(control.host_trusted(self.cfg))
        with self.assertRaisesRegex(ValueError, 'No valid SSH host pin'):
            control.trust(self.cfg, {})

    def test_success_persists_and_does_not_rewrite_pin(self):
        before = self.pin.read_bytes()
        with patch.object(storage.SSH, 'rsync', return_value='') as transfer:
            self.assertTrue(control.trust(self.cfg, {})['settings']['host_trusted'])
            self.assertTrue(transfer.call_args.kwargs['read'])
            self.assertEqual(transfer.call_args.kwargs['timeout'], 25)
        self.assertEqual(before, self.pin.read_bytes())
        self.assertTrue(control.dispatch('settings', self.cfg, {})['settings']['host_trusted'])

    def test_mismatch_is_logged_fails_and_persists_until_success(self):
        real_run = subprocess.run
        def run(args, **kwargs):
            if args[0] == 'ssh-keygen':
                return real_run(args, **kwargs)
            return subprocess.CompletedProcess(args, 255, b'', b'REMOTE HOST IDENTIFICATION HAS CHANGED!')
        stderr = io.StringIO()
        with patch.object(storage, 'backend', wraps=storage.backend), patch.object(control, 'backend', side_effect=lambda cfg: storage.SSH(cfg, run=run)), contextlib.redirect_stderr(stderr):
            with self.assertRaisesRegex(RuntimeError, 'SSH host verification failed'):
                control.trust(self.cfg, {})
        self.assertIn('REMOTE HOST IDENTIFICATION HAS CHANGED', stderr.getvalue())
        state = control.dispatch('settings', self.cfg, {})['settings']
        self.assertFalse(state['host_trusted'])
        self.assertTrue(state['host_error'])
        with patch.object(storage.SSH, 'rsync', return_value=''):
            self.assertTrue(control.trust(self.cfg, {})['settings']['host_trusted'])

    def test_strict_options_for_both_credentials(self):
        for read in (False, True):
            command = storage.SSH(self.cfg).command(read)
            for option in ('StrictHostKeyChecking=yes', 'GlobalKnownHostsFile=/dev/null', 'UpdateHostKeys=no', 'UserKnownHostsFile=' + str(self.pin)):
                self.assertIn(option, command)

    def test_helper_failure_is_json_and_logged(self):
        output, errors = io.StringIO(), io.StringIO()
        with patch.object(control, 'load', side_effect=OSError('fixture config unreadable')), patch.object(control.sys, 'argv', ['control', 'settings']), contextlib.redirect_stdout(output), contextlib.redirect_stderr(errors):
            self.assertEqual(control.main(), 1)
        self.assertFalse(json.loads(output.getvalue())['success'])
        self.assertIn('fixture config unreadable', errors.getvalue())

    def test_recovery_key_rotation_invalidates_old_authentication_failure(self):
        read_key=Path(self.cfg['runtime'])/'read-key'
        self.cfg['ssh_read_key']=str(read_key)
        read_key.write_text('old-fixture-credential')
        from backupmanager.errors import Failure
        with patch.object(storage.SSH,'rsync',side_effect=Failure('ssh_authentication')):
            with self.assertRaises(Failure): control.trust(self.cfg,{})
        self.assertEqual(control.host_status(self.cfg)['host_error_code'],'ssh_authentication')
        read_key.write_text('replacement-fixture-credential')
        result=control.host_status(self.cfg)
        self.assertTrue(result['host_trusted'])  # The pin remains; new authentication is not inferred.
        self.assertEqual(result['host_error'],'')
