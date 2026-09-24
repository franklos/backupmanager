"""Legacy write-only upgrade and actual atomic provider authorization on private fixtures."""
import os
import pwd
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from test_runtime import client_helpers, config, provider_helpers


class UpgradeKeyTests(unittest.TestCase):
    def test_write_only_key_is_preserved_and_distinct_read_key_is_added(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            write = root / 'legacy-key'
            subprocess.run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(write)],check=True)
            original = write.read_bytes()
            # Older installations may lack the .pub companion.
            Path(str(write)+'.pub').unlink()
            legacy = root/'backupmanager.conf'
            legacy.write_text('SSH_KEY='+str(write)+'\nBACKUP_HOST=storage.example\nBACKUP_PATH=/\n')
            cfg = config.legacy(legacy)
            with patch.object(client_helpers.pwd,'getpwnam',return_value=pwd.getpwuid(os.getuid())):
                client_helpers.ensure_keys(cfg)
                first = Path(cfg['ssh_read_key']).read_bytes()
                client_helpers.ensure_keys(cfg)
            self.assertEqual(write.read_bytes(), original)
            self.assertEqual(Path(cfg['ssh_read_key']).read_bytes(), first)
            self.assertNotEqual(client_helpers.public_key(write),client_helpers.public_key(cfg['ssh_read_key']))
            self.assertEqual(write.stat().st_mode & 0o777,0o600)

    def test_bm000007_key_rotation_preserves_storage_and_other_clients(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); storage=root/'storage'; storage.mkdir()
            account=root/'account'; (account/'.ssh').mkdir(parents=True)
            keys=[]
            for i in range(4):
                path=root/('key'+str(i))
                subprocess.run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(path)],check=True)
                keys.append(client_helpers.public_key(path))
            allocation=storage/'BM-000007'; allocation.mkdir()
            for name in ('data','database','config','generations'):
                (allocation/name).mkdir(); (allocation/name/'existing').write_bytes(b'keep recovery data')
            old=f'restrict,command="/usr/bin/rrsync -wo -munge {allocation}" {keys[0]} bm-client=BM-000007'
            foreign=f'restrict,command="/usr/bin/rrsync -wo -munge {storage}/BM-000008" {keys[3]} bm-client=BM-000008'
            authorized=account/'.ssh/authorized_keys'; authorized.write_text(old+'\n'+foreign+'\n')
            with patch.object(provider_helpers,'ROOT',storage),patch.object(provider_helpers,'ACCOUNT',account),patch.object(provider_helpers.pwd,'getpwnam',return_value=pwd.getpwuid(os.getuid())):
                provider_helpers.install({'client_id':'BM-000007','write_key':keys[1],'read_key':keys[2]})
            text=authorized.read_text()
            self.assertNotIn(keys[0],text)
            self.assertIn(foreign,text)
            self.assertEqual(text.count('bm-client=BM-000007'),1)
            self.assertEqual(text.count('bm-restore=BM-000007'),1)
            self.assertIn(f'rrsync -wo -munge {allocation}',text)
            self.assertIn(f'rrsync -ro -munge {allocation}',text)
            for name in ('data','database','config','generations'):
                self.assertEqual((allocation/name/'existing').read_bytes(),b'keep recovery data')

    def test_recovery_activation_can_retry_a_partial_move(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            cfg=config.validate({'runtime':directory,'source_id':'fixture-source',
                'ssh_key':str(root/'write'),'ssh_read_key':str(root/'read')})
            with patch.object(client_helpers.pwd,'getpwnam',return_value=pwd.getpwuid(os.getuid())):
                client_helpers.ensure_keys(cfg)
                info=client_helpers.request_info(cfg,recovery=True)
            original_replace=os.replace
            def interrupted(source,target):
                if Path(source).name=='id_ed25519_restore': raise OSError('fixture interrupted activation')
                original_replace(source,target)
            with patch.object(client_helpers.os,'replace',side_effect=interrupted),self.assertRaises(OSError):
                client_helpers.activate(cfg)
            client_helpers.activate(cfg)
            client_helpers.activate(cfg)
            self.assertEqual(client_helpers.public_key(cfg['ssh_key']),info['public_key'])
            self.assertEqual(client_helpers.public_key(cfg['ssh_read_key']),info['restore_public_key'])
            self.assertEqual(Path(cfg['ssh_key']+'.pub').read_text().strip(),info['public_key'])

    def test_missing_staged_read_key_does_not_replace_live_write_key(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            cfg=config.validate({'runtime':directory,'source_id':'fixture-source',
                'ssh_key':str(root/'write'),'ssh_read_key':str(root/'read')})
            with patch.object(client_helpers.pwd,'getpwnam',return_value=pwd.getpwuid(os.getuid())):
                client_helpers.ensure_keys(cfg)
                client_helpers.request_info(cfg,recovery=True)
            original=Path(cfg['ssh_key']).read_bytes()
            (root/'recovery/id_ed25519_restore').unlink()
            with self.assertRaises(ValueError): client_helpers.activate(cfg)
            self.assertEqual(Path(cfg['ssh_key']).read_bytes(),original)


    def test_legacy_staged_permissions_and_approved_pair_binding(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            cfg = config.validate({'runtime':directory, 'source_id':'fixture-source',
                'ssh_key':str(root/'write'), 'ssh_read_key':str(root/'read')})
            with patch.object(client_helpers.pwd,'getpwnam',return_value=pwd.getpwuid(os.getuid())):
                client_helpers.ensure_keys(cfg)
                expected = client_helpers.request_info(cfg, recovery=True)
                staged = root/'recovery/id_ed25519'
                staged.chmod(0o644)
                self.assertEqual(client_helpers.request_info(cfg, recovery=True), expected)
                self.assertEqual(staged.stat().st_mode & 0o777, 0o600)
            original = Path(cfg['ssh_key']).read_bytes()
            with self.assertRaisesRegex(ValueError, 'approved request'):
                client_helpers.activate(cfg, expected | {'public_key':client_helpers.public_key(cfg['ssh_key'])})
            self.assertEqual(Path(cfg['ssh_key']).read_bytes(), original)
            client_helpers.activate(cfg, expected)
            client_helpers.activate(cfg, expected)
            self.assertEqual(client_helpers.public_key(cfg['ssh_key']), expected['public_key'])
