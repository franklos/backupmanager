"""Prevent successful CLI upgrades from hiding an unchanged legacy HTTP deployment."""
import importlib.util
import tempfile
import shutil
import subprocess
import unittest
from unittest.mock import patch
from pathlib import Path

spec = importlib.util.spec_from_file_location('web_check', Path(__file__).resolve().parents[1]/'installer/web_check.py')
web = importlib.util.module_from_spec(spec)
spec.loader.exec_module(web)

def fpm_fixture(root, version):
    """Installed FPM metadata only; fixture binaries are never executed."""
    modules = root/'etc/apache2/mods-enabled'; modules.mkdir(parents=True, exist_ok=True)
    (modules/'proxy_fcgi.load').write_text('LoadModule proxy_fcgi_module /usr/lib/apache2/modules/mod_proxy_fcgi.so\n')
    pools = root / ('etc/php/' + version + '/fpm/pool.d')
    pools.mkdir(parents=True, exist_ok=True)
    (pools.parent/'php-fpm.conf').write_text('[global]\ninclude=/etc/php/' + version + '/fpm/pool.d/*.conf\n')
    binary = root / ('usr/sbin/php-fpm' + version)
    binary.parent.mkdir(parents=True, exist_ok=True)
    binary.write_text('#!/bin/sh\nexit 99\n')
    binary.chmod(0o755)
    unit = root / ('lib/systemd/system/php' + version + '-fpm.service')
    unit.parent.mkdir(parents=True, exist_ok=True)
    unit.write_text('[Service]\nExecStart=/usr/sbin/php-fpm' + version + ' --nodaemonize\n')
    return pools


class WebDeploymentTests(unittest.TestCase):
    def test_legacy_apache_root_and_default_pool_are_not_ready(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            sites = root/'etc/apache2/sites-enabled'; sites.mkdir(parents=True)
            pools = fpm_fixture(root, '8.3')
            (sites/'provider.conf').write_text('DocumentRoot /var/www/backupmanager-provider/public\n')
            (pools/'www.conf').write_text('[www]\nuser = www-data\ngroup = www-data\nlisten = /run/php/php8.3-fpm.sock\n')
            errors = web.web_errors(root)
            self.assertEqual(len(errors),3)
            self.assertTrue(any('installed CLI code' in error for error in errors))
            repo = Path(__file__).resolve().parents[1]
            (sites/'provider.conf').write_text((repo/'provider/config/apache.conf.example').read_text())
            (pools/'provider.conf').write_text((repo/'provider/config/php-fpm.conf.example').read_text())
            self.assertEqual(web.web_errors(root),[])
            (pools/'provider.conf').write_text((pools/'provider.conf').read_text().replace('user = bmprovider','user = www-data'))
            self.assertTrue(web.web_errors(root))


    @unittest.skipUnless(shutil.which('apache2'), 'Apache syntax checker unavailable')
    def test_apache_example_syntax_without_starting_server(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            public=root/'public'; public.mkdir()
            repo=Path(__file__).resolve().parents[1]
            snippet=(repo/'provider/config/apache.conf.example').read_text().replace('/opt/backupmanager-provider/public',str(public))
            modules=['mpm_event','authz_core','dir','proxy','proxy_fcgi','setenvif']
            config='ServerRoot "'+str(root)+'"\nServerName fixture.example.test\nPidFile '+str(root/'pid')+'\nErrorLog '+str(root/'error.log')+'\n'
            config+=''.join('LoadModule '+module+'_module /usr/lib/apache2/modules/mod_'+module+'.so\n' for module in modules)
            config+='<VirtualHost 127.0.0.1:18443>\n'+snippet+'\n</VirtualHost>\n'
            file=root/'apache.conf';file.write_text(config)
            result=subprocess.run(['apache2','-t','-f',str(file)],capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)


class FpmPortabilityTests(unittest.TestCase):
    def test_supported_hosts_and_actual_provider_pool(self):
        repo = Path(__file__).resolve().parents[1]
        for version in ('8.3', '8.4'):
            with self.subTest(version=version), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                pools = fpm_fixture(root, version)
                selected = web.detect_fpm(root)
                self.assertEqual(selected['version'], version)
                self.assertEqual(selected['binary'], '/usr/sbin/php-fpm' + version)
                self.assertEqual(selected['service'], 'php' + version + '-fpm.service')
                self.assertEqual(selected['pool'], '/etc/php/' + version + '/fpm/pool.d/backupmanager-provider.conf')
                sites = root/'etc/apache2/sites-enabled'; sites.mkdir(parents=True)
                (sites/'provider.conf').write_text((repo/'provider/config/apache.conf.example').read_text())
                (pools/'backupmanager-provider.conf').write_text((repo/'provider/config/php-fpm.conf.example').read_text())
                self.assertEqual(web.web_errors(root), [])
                # An identity in another section must not validate the listener.
                (pools/'backupmanager-provider.conf').write_text('[wrong]\nuser=bmprovider\ngroup=bmprovider\n[provider]\nlisten=' + web.SOCKET + '\nuser=www-data\ngroup=www-data\n')
                self.assertTrue(any('user and group' in error for error in web.web_errors(root)))

    def test_multiple_versions_selection_and_existing_pool_preserved(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            first = fpm_fixture(root, '8.3')
            second = fpm_fixture(root, '8.4')
            self.assertEqual(web.detect_fpm(root, active_versions=['8.4'])['version'], '8.4')
            for active in ([], ['8.3', '8.4']):
                with self.assertRaisesRegex(ValueError, 'Ambiguous'):
                    web.detect_fpm(root, active_versions=active)
            self.assertEqual(web.detect_fpm(root, version='8.3')['version'], '8.3')
            with self.assertRaisesRegex(ValueError, 'not usable'):
                web.detect_fpm(root, version='9.9')
            pool = first/'backupmanager-provider.conf'
            pool.write_text('[provider]\nuser=bmprovider\ngroup=bmprovider\nlisten=' + web.SOCKET + '\n')
            before = pool.read_bytes()
            self.assertEqual(web.detect_fpm(root, active_versions=['8.4'])['version'], '8.3')
            self.assertEqual(pool.read_bytes(), before)
            with self.assertRaisesRegex(ValueError, 'refusing selection'):
                web.detect_fpm(root, version='8.4')
            (second/'provider.conf').write_bytes(before)
            with self.assertRaisesRegex(ValueError, 'Multiple.*pools'):
                web.detect_fpm(root, version='8.3')

    def test_no_usable_fpm_never_guesses_from_cli(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            cli = root/'usr/bin/php'; cli.parent.mkdir(parents=True)
            cli.write_text('PHP 8.4 CLI'); cli.chmod(0o755)
            with self.assertRaisesRegex(ValueError, 'No usable PHP-FPM.*No path was guessed'):
                web.detect_fpm(root)
            pools = fpm_fixture(root, '8.4')
            (pools.parent/'php-fpm.conf').write_text('[global]\n;include=/etc/php/8.4/fpm/pool.d/*.conf\n')
            with self.assertRaisesRegex(ValueError, 'No usable PHP-FPM'):
                web.detect_fpm(root)
            fpm_fixture(root, '8.4')
            (root/'usr/sbin/php-fpm8.4').chmod(0o644)
            with self.assertRaisesRegex(ValueError, 'No usable PHP-FPM'):
                web.detect_fpm(root)

    def test_stale_pool_cannot_validate_different_installation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            fpm_fixture(root, '8.4')
            old = root/'etc/php/8.3/fpm/pool.d'; old.mkdir(parents=True)
            (old/'provider.conf').write_text('[provider]\nlisten=' + web.SOCKET + '\nuser=bmprovider\ngroup=bmprovider\n')
            with self.assertRaisesRegex(ValueError, 'unusable.*8.3'):
                web.detect_fpm(root)

    def test_installer_preflight_stops_before_any_mutation(self):
        import sys
        repo = Path(__file__).resolve().parents[1]
        spec = importlib.util.spec_from_file_location('installer_under_test', repo/'installer/install.py')
        installer = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(installer)
        with patch.dict(sys.modules, {'web_check': web}), \
                patch.object(sys, 'argv', ['install.py', 'provider', '--apply']), \
                patch.object(installer.os, 'geteuid', return_value=0), \
                patch.object(web, 'detect_fpm', side_effect=ValueError('No usable PHP-FPM installation')), \
                patch.object(installer, 'run') as run, \
                patch.object(installer.shutil, 'copyfile') as copy:
            self.assertEqual(installer.main(), 2)
            run.assert_not_called()
            copy.assert_not_called()

    def test_missing_apache_fastcgi_module_is_not_ready(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            pools = fpm_fixture(root, '8.4')
            repo = Path(__file__).resolve().parents[1]
            sites = root/'etc/apache2/sites-enabled'; sites.mkdir(parents=True)
            (sites/'provider.conf').write_text((repo/'provider/config/apache.conf.example').read_text())
            (pools/'provider.conf').write_text((repo/'provider/config/php-fpm.conf.example').read_text())
            (root/'etc/apache2/mods-enabled/proxy_fcgi.load').unlink()
            self.assertTrue(any('proxy_fcgi module is not enabled' in e for e in web.web_errors(root)))

    def test_redundant_basic_auth_is_reported(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);pools=fpm_fixture(root,'8.3')
            repo=Path(__file__).resolve().parents[1]
            sites=root/'etc/apache2/sites-enabled';sites.mkdir(parents=True)
            (sites/'provider.conf').write_text((repo/'provider/config/apache.conf.example').read_text()+'\n<Location /admin/>\nAuthType Basic\nRequire valid-user\n</Location>\n')
            (pools/'provider.conf').write_text((repo/'provider/config/php-fpm.conf.example').read_text())
            self.assertTrue(any('second admin login' in e for e in web.web_errors(root)))
