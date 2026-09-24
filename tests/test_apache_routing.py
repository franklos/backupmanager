"""Actual isolated Apache -> Unix socket -> PHP-FPM front-controller requests."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.request
import urllib.error

REPO = Path(__file__).resolve().parents[1]
FPM = next((str(p) for p in sorted(Path('/usr/sbin').glob('php-fpm*')) if os.access(p, os.X_OK)), None)

@unittest.skipUnless(shutil.which('apache2') and FPM, 'Apache and PHP-FPM required')
class ApacheRoutingTests(unittest.TestCase):
    def test_real_front_controller(self):
        self.exercise('working')

    def test_missing_module_fails_closed(self):
        self.exercise('guarded')

    def test_legacy_missing_module_reproduces_false_200_source(self):
        self.exercise('legacy')

    def exercise(self, mode):
        with tempfile.TemporaryDirectory(prefix='bm-apache-') as directory:
            root = Path(directory)
            public = root/'public'; public.mkdir()
            # Exercise the real Router with production REQUEST_URI dispatch semantics.
            (public/'index.php').write_text('''<?php
require ''' + repr(str(REPO/'provider/src/Response.php')) + ''';
require ''' + repr(str(REPO/'provider/src/Router.php')) + ''';
$r = new BackupManager\\Provider\\Router();
foreach (['POST', 'GET'] as $method) {
 $r->add($method, '#^/api/v1/(recovery-requests|management/clients|recovery-requests/REC-old)$#', function($route) {
 BackupManager\\Provider\\Response::json(['success'=>true,'route'=>$route,'method'=>$_SERVER['REQUEST_METHOD'],'uri'=>$_SERVER['REQUEST_URI'],'body'=>file_get_contents('php://input'),'authorized'=>(($_SERVER['HTTP_AUTHORIZATION'] ?? '')==='Bearer fixture-secret')]);
 });
}
$r->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
''')
            admin=public/'admin'; admin.mkdir()
            (admin/'index.php').write_text('<?php header("Content-Type: application/json"); echo json_encode(["admin"=>true]);')
            (public/'secret.php').write_text('<?php echo "DO NOT SERVE";')
            sock = root/'fpm.sock'
            (root/'fpm.conf').write_text(f'[global]\nerror_log={root}/fpm.log\n[fixture]\nlisten={sock}\npm=ondemand\npm.max_children=2\n')
            with socket.socket() as probe:
                probe.bind(('127.0.0.1',0)); port=probe.getsockname()[1]
            snippet=(REPO/'provider/config/apache.conf.example').read_text().replace('/opt/backupmanager-provider/public',str(public)).replace('/run/php/backupmanager-provider.sock',str(sock))
            if mode == 'legacy':
                snippet=snippet.replace('    <IfModule proxy_fcgi_module>\n        Require all granted\n    </IfModule>\n', '    Require all granted\n')
                snippet=snippet.replace('    <IfModule !proxy_fcgi_module>\n        Require all denied\n    </IfModule>\n', '')
            if mode == 'legacy':
                snippet=snippet.replace('        <IfModule !proxy_fcgi_module>\n            Require all denied\n        </IfModule>\n','')
            snippet+=f'\n<Directory {admin}>\nRequire all granted\nFallbackResource disabled\n</Directory>\n'
            modules=['mpm_event','authz_core','dir','proxy','proxy_fcgi','rewrite','setenvif']
            if mode != 'working': modules.remove('proxy_fcgi')
            config=f'ServerRoot {root}\nServerName fixture.test\nListen 127.0.0.1:{port}\nPidFile {root}/apache.pid\nErrorLog {root}/apache.log\n'
            config+=''.join(f'LoadModule {m}_module /usr/lib/apache2/modules/mod_{m}.so\n' for m in modules)
            config+=f'<VirtualHost 127.0.0.1:{port}>\n{snippet}\n</VirtualHost>\n'
            (root/'apache.conf').write_text(config)
            processes=[]
            try:
                processes.append(subprocess.Popen([FPM,'-F','-y',str(root/'fpm.conf')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL))
                processes.append(subprocess.Popen(['apache2','-X','-f',str(root/'apache.conf')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL))
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                    except OSError: time.sleep(.05)
                for _ in range(100):
                    if sock.exists(): break
                    time.sleep(.05)
                self.assertTrue(sock.exists(), 'Isolated FPM failed to create socket')
                for path, method in [('/api/v1/recovery-requests','POST'),('/api/v1/management/clients','GET'),('/api/v1/recovery-requests/REC-old','GET')]:
                    req=urllib.request.Request(f'http://127.0.0.1:{port}'+path+'?probe=1',data=b'{"fixture":true}' if method=='POST' else None,method=method,headers={'Authorization':'Bearer fixture-secret'})
                    if mode == 'guarded':
                        with self.assertRaises(urllib.error.HTTPError) as caught:
                            urllib.request.urlopen(req, timeout=5)
                        self.assertEqual(caught.exception.code, 403)
                        continue
                    with urllib.request.urlopen(req,timeout=5) as response:
                        body=response.read()
                        if mode == 'legacy':
                            self.assertEqual(response.status,200)
                            self.assertEqual(body,(public/'index.php').read_bytes())
                            continue
                        self.assertEqual(response.headers.get_content_type(),'application/json',repr(body[:100]))
                        data=json.loads(body)
                        self.assertEqual(data['route'],path.removeprefix('/api/v1/'))
                        self.assertEqual(data['uri'],path+'?probe=1')
                        self.assertEqual(data['method'],method)
                        self.assertTrue(data['authorized'])
                        if method=='POST': self.assertEqual(json.loads(data['body']),{'fixture':True})
                if mode == 'guarded':
                    with self.assertRaises(urllib.error.HTTPError) as caught:
                        urllib.request.urlopen(f'http://127.0.0.1:{port}/admin/', timeout=5)
                    self.assertEqual(caught.exception.code,403)
                if mode != 'working': return
                for path, status in [('/api/v1/missing',404),('/secret.php',403)]:
                    with self.assertRaises(urllib.error.HTTPError) as caught:
                        urllib.request.urlopen(f'http://127.0.0.1:{port}'+path,timeout=5)
                    self.assertEqual(caught.exception.code,status)
            finally:
                for process in reversed(processes):
                    process.terminate()
                    try: process.wait(timeout=5)
                    except subprocess.TimeoutExpired: process.kill(); process.wait()
