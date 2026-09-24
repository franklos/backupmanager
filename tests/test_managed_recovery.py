"""Canonical managed assignment repair never rotates keys or touches stored backups."""
import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'runtime'))
from backupmanager import client_helpers, config, control


class ManagedRecoveryTests(unittest.TestCase):
    def test_existing_approved_client_refresh_and_manual_roundtrip(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / 'runtime.json'
            keys = [root / 'write-key', root / 'read-key']
            generation = root / 'storage/generations/20260923T010000Z-abcdef012345/data.000000'
            generation.parent.mkdir(parents=True)
            generation.write_bytes(b'existing generation')
            for key, content in zip(keys, [b'approved write key', b'approved read key']):
                key.write_bytes(content)
                key.chmod(0o600)
            manual = dict(ssh_host='manual.example', ssh_port=2222, ssh_user='archive', ssh_path='/archive')
            cfg = config.remember_storage(config.validate(dict(runtime=directory, client_id='BM-000007',
                ssh_host='localhost', ssh_key=str(keys[0]), ssh_read_key=str(keys[1]),
                storage_profiles={'ssh_manual': manual})))
            connection = dict(client_id='BM-000007', host='backup.ncdev.local', port=22,
                              user='backupstore', path='/', activate_recovery=False, select_managed=True)
            protected = {p: (p.read_bytes(), p.stat().st_mode, p.stat().st_ino) for p in [*keys, generation]}
            for mode in ['managed', 'manual']:
                initial = cfg if mode == 'managed' else config.validate(cfg | manual | {'credential_mode': 'manual'})
                config.atomic_json(target, initial)
                with patch.object(client_helpers, 'load', side_effect=lambda: config.load(target)), \
                     patch.object(client_helpers, 'CONFIG', str(target)), \
                     patch.object(client_helpers, 'payload', return_value=connection), \
                     patch.object(client_helpers.sys, 'argv', ['backupmanager-apply-provider-config']), \
                     patch.object(client_helpers, 'activate') as activate, \
                     patch.object(control, 'apply_schedule') as schedule, redirect_stdout(io.StringIO()):
                    self.assertEqual(client_helpers.main(), 0)
                    # The exact repair is repeatable after recovery staging has gone away.
                    self.assertEqual(client_helpers.main(), 0)
                    activate.assert_not_called()
                    schedule.assert_not_called()
                saved = config.load(target)
                self.assertEqual(saved['client_id'], 'BM-000007')
                self.assertEqual(saved['credential_mode'], 'managed')
                self.assertEqual(saved['destination'], 'ssh')
                self.assertEqual(saved['ssh_host'], 'backup.ncdev.local')
                self.assertEqual(saved['storage_profiles']['ssh_managed'], dict(
                    client_id='BM-000007', ssh_host='backup.ncdev.local', ssh_port=22,
                    ssh_user='backupstore', ssh_path='/'))
                self.assertEqual(saved['storage_profiles']['ssh_manual'], manual)
                with patch.object(control, 'host_status', return_value={}), \
                     patch.object(client_helpers, 'public_key', return_value='public fixture'):
                    public = control.dispatch('settings', saved, {})['settings']
                self.assertEqual(public['credential_mode'], 'managed')
                self.assertEqual(public['storage_profiles']['ssh_managed']['ssh_host'], public['ssh_host'])
                with patch.object(control, 'CONFIG', str(target)), patch.object(control, 'apply_schedule'):
                    control.save(saved, {'credential_mode': 'manual'})
                    control.save(config.load(target), {'credential_mode': 'managed', 'ssh_host': '', 'ssh_user': '', 'ssh_path': ''})
                self.assertEqual(config.load(target)['ssh_host'], 'backup.ncdev.local')
                self.assertEqual({p: (p.read_bytes(), p.stat().st_mode, p.stat().st_ino) for p in protected}, protected)
                self.assertEqual({k: saved[k] for k in ('days', 'time', 'timezone')},
                                 {k: initial[k] for k in ('days', 'time', 'timezone')})
                # Refuse identity changes or attempted recovery activation through refresh.
                before = target.read_bytes()
                for invalid in [connection | {'client_id': 'BM-000008'}, connection | {'activate_recovery': True}]:
                    with patch.object(client_helpers, 'load', side_effect=lambda: config.load(target)), \
                         patch.object(client_helpers, 'CONFIG', str(target)), \
                         patch.object(client_helpers, 'payload', return_value=invalid), \
                         patch.object(client_helpers.sys, 'argv', ['backupmanager-apply-provider-config']), \
                         redirect_stdout(io.StringIO()):
                        self.assertEqual(client_helpers.main(), 1)
                    self.assertEqual(target.read_bytes(), before)
