"""Exercise actual rsync/rrsync against fixture storage, without SSH or network."""
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'runtime'))
from backupmanager.config import validate
from backupmanager.storage import SSH

@unittest.skipUnless(shutil.which('rsync') and shutil.which('rrsync'), 'rsync/rrsync unavailable')
class RestrictedTransportTests(unittest.TestCase):
    def test_object_lifecycle_and_enforced_restrictions(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            remote = root / 'remote'
            remote.mkdir()
            (root / 'transfer').mkdir()
            bridge = root / 'bridge.py'
            bridge.write_text('''import os, shlex, sys
mode, root = sys.argv[1:3]
os.environ['SSH_ORIGINAL_COMMAND'] = shlex.join(sys.argv[sys.argv.index('rsync'):])
os.execv('/usr/bin/rrsync', ['rrsync', mode, '-munge', root])
''')
            cfg = validate({'runtime': str(root), 'ssh_host': 'fixture.example.test'})
            class LocalSSH(SSH):
                def command(self, read=False):
                    return [sys.executable, str(bridge), '-ro' if read else '-wo', str(remote)]
            backend = LocalSSH(cfg)
            generation = '20260101T000000Z-abcdef123456'
            key = 'generations/' + generation + '/config.000000'
            backend.put(key, b'fixture artifact')
            self.assertEqual(backend.get(key), b'fixture artifact')
            self.assertIn(key, backend.keys())
            with self.assertRaises(RuntimeError):
                backend.rsync([backend.remote(key), str(root / 'stolen')], read=False)
            with self.assertRaises(RuntimeError):
                backend.rsync([str(root / 'bridge.py'), backend.remote('denied')], read=True)
            with self.assertRaises(RuntimeError):
                backend.rsync([backend.remote('../../outside'), str(root / 'escape')], read=True)
            env = dict(os.environ, SSH_ORIGINAL_COMMAND='touch ' + str(root / 'must-not-exist'))
            result = subprocess.run(['rrsync', '-wo', str(remote)], env=env, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((root / 'must-not-exist').exists())
            backend.delete_generation(generation)
            self.assertEqual(backend.keys(), [])
