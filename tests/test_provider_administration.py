"""Isolated admin session, canonical config and atomic credential rollback boundaries."""
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import sys
REPO=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(REPO/'runtime'))
from backupmanager import provider_helpers as helpers

class ProviderAdministrationTests(unittest.TestCase):
    def test_single_admin_session_authentication(self):
        with tempfile.TemporaryDirectory(prefix='bm-admin-session-',dir='/tmp') as directory:
            root=Path(directory);(root/'sessions').mkdir();(root/'limits').mkdir()
            password='fixture-only-private-password'
            hashed=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',password]).decode()
            (root/'password.hash').write_text(hashed)
            (root/'config.php').write_text("<?php return ['admin'=>['password_hash_file'=>"+repr(str(root/'password.hash'))+"]];")
            env=dict(os.environ,BM_ADMIN_SESSION_ROOT=str(root))
            def request(**data):
                result=subprocess.run(['php',str(REPO/'tests/provider_admin_session.php')],input=json.dumps(data),capture_output=True,text=True,env=env,check=True)
                self.assertNotIn('Warning',result.stdout+result.stderr)
                return result.stdout,json.loads((root/'response.json').read_text())
            dutch,_=request(lang='nl');self.assertIn('Beheerderswachtwoord',dutch)
            html,first=request()
            self.assertIn('Administrator password',html)
            self.assertFalse(any('WWW-Authenticate' in h for h in first['headers']))
            self.assertTrue(any('frame-ancestors' in h for h in first['headers']))
            html,bad=request(session=first['session'],post={'action':'login','csrf':'wrong','password':password})
            self.assertEqual(bad['status'],401);self.assertNotIn('AUTHENTICATED',html)
            _,login=request(session=first['session'],post={'action':'login','csrf':first['csrf'],'password':password})
            self.assertNotEqual(login['session'],first['session'])
            self.assertIn('Location: ./',login['headers'])
            html,authenticated=request(session=login['session']);self.assertEqual(html,'AUTHENTICATED')
            # Password rotation invalidates an established session.
            (root/'password.hash').write_text(subprocess.check_output(['php','-r','echo password_hash("rotated-fixture", PASSWORD_DEFAULT);']).decode())
            html,_=request(session=login['session']);self.assertIn('Administrator password',html)
            html,denied=request(https='off');self.assertEqual(denied['status'],403);self.assertNotIn('AUTHENTICATED',html)

    def test_canonical_configuration_and_storage_validation(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);config=root/'config.php'
            for host,port,valid in [('storage.example.test',22,True),('storage.example.test','22',True),('',22,False),('https://storage.example.test',22,False),('storage.example.test',0,False),('storage.example.test','22oops',False)]:
                # Explicit constructor injection only in isolated tests; every production entry point uses Config().
                config.write_text("<?php return ['storage'=>['host'=>"+repr(host)+",'port'=>"+repr(port)+"]];")
                code='require $argv[1]."/provider/src/Config.php"; require $argv[1]."/provider/src/StorageEndpoint.php"; $c=new BackupManager\\Provider\\Config($argv[2]); try { echo json_encode(BackupManager\\Provider\\StorageEndpoint::configured($c)); } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(2); }'
                result=subprocess.run(['php','-r',code,str(REPO),str(config)],capture_output=True,text=True)
                self.assertEqual(result.returncode,0 if valid else 2,result.stderr)
                if valid:self.assertEqual(json.loads(result.stdout)['port'],22)
                else:self.assertIn('storage_not_configured',result.stderr)
            self.assertIn("public const FILE = '/etc/backupmanager-provider/config.php'",(REPO/'provider/src/Config.php').read_text())
            for path in list((REPO/'provider/bin').glob('*.php'))+list((REPO/'provider/public').rglob('*.php')):
                self.assertNotIn("'/config/config.php'",path.read_text(),str(path))

    def test_staging_preserves_private_provider_configuration(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);file=root/'etc/backupmanager-provider/config.php';file.parent.mkdir(parents=True)
            content=b"<?php return ['storage'=>['host'=>'provider.example.test','port'=>2222],'database'=>['password'=>'fixture-private']];"
            file.write_bytes(content)
            subprocess.run([sys.executable,str(REPO/'installer/install.py'),'provider','--destdir',str(root)],capture_output=True,check=True)
            self.assertEqual(file.read_bytes(),content)
            self.assertFalse((root/'opt/backupmanager-provider/config/config.php').exists())

    def test_failed_rotation_restores_exact_legacy_authorizations(self):
        import base64, struct
        def key(letter):
            return 'ssh-ed25519 '+base64.b64encode(struct.pack('>I',11)+b'ssh-ed25519'+struct.pack('>I',32)+letter*32).decode()
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);account=root/'account';(account/'.ssh').mkdir(parents=True)
            storage=root/'storage';storage.mkdir();allocation=storage/'BM-000007';allocation.mkdir()
            sentinel=allocation/'existing-generation';sentinel.write_bytes(b'original generation bytes')
            auth=account/'.ssh/authorized_keys'
            old='restrict,command="/usr/bin/rrsync -wo -munge '+str(allocation)+'" '+key(b'a')+' bm-client=BM-000007\n'
            other='restrict,command="/usr/bin/rrsync -wo -munge /fixture/other" '+key(b'd')+' bm-client=BM-000001\n'
            auth.write_text(other+old)
            with patch.object(helpers,'ROOT',storage),patch.object(helpers,'ACCOUNT',account):
                data={'client_id':'BM-000007','write_key':key(b'b'),'read_key':key(b'c'),'transaction':'a'*64,'existing_only':True}
                helpers.install(data)
                self.assertEqual(auth.read_text().count('bm-client=BM-000007'),1)
                self.assertEqual(auth.read_text().count('bm-restore=BM-000007'),1)
                with self.assertRaises(RuntimeError): helpers.install(data)
                helpers.install({'client_id':'BM-000007','operation':'rollback','transaction':'a'*64})
                self.assertEqual(auth.read_text(),other+old)
                self.assertEqual(sentinel.read_bytes(),b'original generation bytes')
                helpers.install(data)
                helpers.install({'client_id':'BM-000007','operation':'commit','transaction':'a'*64})
                self.assertFalse(helpers.transaction_file('BM-000007').exists())
                self.assertIn(other,auth.read_text())
                with self.assertRaises(ValueError): helpers.install(dict(data,client_id='BM-000008'))
                self.assertFalse((storage/'BM-000008').exists())
