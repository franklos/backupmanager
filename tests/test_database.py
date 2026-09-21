"""Real MariaDB, but only a private temporary datadir and Unix socket; no host service."""
import os
import pwd
import shutil
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]

@unittest.skipUnless(shutil.which('mariadbd') and shutil.which('mariadb-install-db'), 'Isolated MariaDB tools unavailable')
class DatabaseIntegrationTests(unittest.TestCase):
    def test_private_database_upgrade_and_lifecycle(self):
        with tempfile.TemporaryDirectory(prefix='bm-integration-', dir='/tmp') as directory:
            root = Path(directory)
            socket = root / 'server.sock'
            account = pwd.getpwuid(os.getuid()).pw_name
            initialized = subprocess.run(['mariadb-install-db', '--no-defaults', '--datadir=' + str(root / 'data'), '--auth-root-authentication-method=normal', '--skip-test-db', '--user=' + account], capture_output=True, timeout=60)
            self.assertEqual(initialized.returncode, 0, 'Isolated database initialization failed: ' + initialized.stderr.decode(errors='replace'))
            server = subprocess.Popen(['mariadbd', '--no-defaults', '--datadir=' + str(root / 'data'), '--socket=' + str(socket), '--pid-file=' + str(root / 'server.pid'), '--skip-networking', '--log-error=' + str(root / 'server.log'), '--innodb-buffer-pool-size=32M', '--innodb-log-file-size=8M', '--skip-log-bin', '--max-connections=10', '--user=' + account], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                deadline = time.monotonic() + 30
                while not socket.exists() and server.poll() is None and time.monotonic() < deadline:
                    time.sleep(0.1)
                self.assertTrue(socket.exists(), 'Isolated database failed to start')
                tools = root / 'bin'
                tools.mkdir()
                sudo = tools / 'sudo'
                sudo.write_text('''#!/usr/bin/python3
import json, os, sys
from pathlib import Path
root = Path(os.environ['BM_TEST_SOCKET']).parent
args = [a for a in sys.argv[1:] if a != '-n']
name = Path(args[0]).name
if name not in ('backupmanager-install-authorized-keys', 'backupmanager-remove-authorized-key', 'backupmanager-remove-storage'):
    raise SystemExit(2)
if name == 'backupmanager-install-authorized-keys':
    data = json.load(sys.stdin)
    assert data['write_key'] != data['read_key']
if (root / 'fail-helper').exists() and (root / 'fail-helper').read_text() == name:
    raise SystemExit(1)
with open(root / 'helper-calls', 'a') as out:
    out.write(name + '\\n')
''')
                sudo.chmod(0o755)
                env = dict(os.environ, BM_TEST_SOCKET=str(socket), PATH=str(tools) + ':/usr/bin:/bin')
                result = subprocess.run(['php', str(REPO / 'tests/provider_database.php')], env=env, capture_output=True, text=True, timeout=60)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertIn('tests passed', result.stdout)
                from test_runtime import FixtureEngine, MemoryStorage
                from backupmanager import config, engine, phpconfig
                class DatabaseEngine(FixtureEngine):
                    dump = engine.Engine.dump
                    def db_command(self, executable):
                        return [executable]
                    def command(self, args, **kwargs):
                        if 'rsync' in args:
                            args = args[args.index('rsync'):]
                        return engine.Engine.command(self, args, **kwargs)
                    def import_app_config(self, data):
                        self.preserved_app = data
                sql = ['mysql', '--no-defaults', '--socket=' + str(socket), '-u', 'root', 'bm_fixture']
                subprocess.run(sql + ['-e', "CREATE TABLE recovery_fixture (value VARCHAR(32)); INSERT INTO recovery_fixture VALUES ('original')"], check=True, capture_output=True)
                nc = root / 'nextcloud'
                (nc / 'config').mkdir(parents=True)
                (nc / 'data').mkdir()
                (nc / 'data/file.txt').write_text('original file')
                runtime = root / 'runtime'
                runtime.mkdir()
                settings = {'dbtype': 'mysql', 'dbhost': 'localhost:' + str(socket), 'dbname': 'bm_fixture', 'dbuser': 'root', 'dbpassword': '',
                            'version': '34.0.0', 'datadirectory': str(nc / 'data')}
                (nc / 'config/config.php').write_text('<?php $CONFIG = ' + phpconfig.render(settings) + ';')
                cfg = config.validate({'runtime': str(runtime), 'nc_path': str(nc), 'data_path': str(nc / 'data')})
                runtime_engine = DatabaseEngine(cfg, MemoryStorage())
                generation = runtime_engine.backup()
                subprocess.run(sql + ['-e', "UPDATE recovery_fixture SET value='changed'"], check=True, capture_output=True)
                (nc / 'data/file.txt').write_text('changed file')
                runtime_engine.restore(generation, 'complete')
                restored = subprocess.run(sql + ['-N', '-e', 'SELECT value FROM recovery_fixture'], check=True, capture_output=True, text=True)
                self.assertEqual(restored.stdout.strip(), 'original')
                self.assertEqual((nc / 'data/file.txt').read_text(), 'original file')
                self.assertEqual(runtime_engine.preserved_app, {'apps': {'backupstatus': {}}})
            finally:
                server.terminate()
                try:
                    server.wait(timeout=15)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()
