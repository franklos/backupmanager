"""One continuous legacy upgrade/recovery/SSH/backup/restore journey; no live paths."""
import base64
import hashlib
import http.server
import ssl
import threading
import json
import os
import pwd
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch
from test_runtime import FixtureEngine, config, control, engine, phpconfig, storage, client_helpers

REPO = Path(__file__).resolve().parents[1]

class JourneyEngine(FixtureEngine):
    """Only Nextcloud OCC/user switching is adapted; archive/SQL/restore are real."""
    dump = engine.Engine.dump
    def db_command(self, executable):
        return [executable]
    def command(self, args, **kwargs):
        if '/usr/local/sbin/backupmanager-config-install' in args:
            from backupmanager import config_install
            import io
            with patch.object(config_install.sys, 'argv', ['backupmanager-config-install', args[-1]]), patch.object(config_install.sys, 'stdin', io.TextIOWrapper(io.BytesIO(kwargs['input']))):
                config_install.main()
            return b''
        if 'rsync' in args:
            args = args[args.index('rsync'):]
        return engine.Engine.command(self, args, **kwargs)
    def import_app_config(self, data):
        self.preserved_app = data

class DirectoryStorage(storage.Storage):
    def __init__(self, root): self.root = root
    def put(self, key, data):
        file = self.root / storage.check_key(key)
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_bytes(data)
    def get(self, key): return (self.root / storage.check_key(key)).read_bytes()
    def keys(self): return [str(p.relative_to(self.root)) for p in self.root.rglob('*') if p.is_file() and storage.OBJECT.fullmatch(str(p.relative_to(self.root)))]
    def delete_generation(self, generation):
        assert storage.GENERATION.fullmatch(generation)
        shutil.rmtree(self.root / 'generations' / generation)

@unittest.skipUnless(all(shutil.which(x) for x in ('openssl','sshd','ssh','rrsync','rsync','mariadbd','mariadb-install-db','php')), 'Journey requires local SSH and MariaDB tools')
class LegacyJourneyTests(unittest.TestCase):
    def test_complete_legacy_upgrade_journey(self):
        with tempfile.TemporaryDirectory(prefix='bm-integration-', dir='/tmp') as directory:
            root = Path(directory)
            account = pwd.getpwuid(os.getuid())
            def run(args, **kwargs):
                result = subprocess.run(args, capture_output=True, timeout=90, **kwargs)
                self.assertEqual(result.returncode, 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace'))
                return result
            sock = root/'server.sock'
            run(['mariadb-install-db','--no-defaults','--datadir='+str(root/'db'),'--auth-root-authentication-method=normal','--skip-test-db','--user='+account.pw_name])
            db = subprocess.Popen(['mariadbd','--no-defaults','--datadir='+str(root/'db'),'--socket='+str(sock),'--pid-file='+str(root/'db.pid'),'--skip-networking','--log-error='+str(root/'db.log'),'--innodb-buffer-pool-size=32M','--innodb-log-file-size=8M','--user='+account.pw_name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            self.addCleanup(lambda: None)
            sshd = None
            https = None
            try:
                for _ in range(300):
                    if sock.exists(): break
                    if db.poll() is not None: self.fail((root/'db.log').read_text())
                    time.sleep(.1)
                self.assertTrue(sock.exists())
                mysql=['mariadb','--no-defaults','--socket='+str(sock),'-u','root','-N']
                def sql(query, database='bm_journey'):
                    return run(mysql + ([database] if database else []), input=query.encode()).stdout.decode().strip()
                sql('CREATE DATABASE bm_journey; CREATE DATABASE bm_nextcloud;', '')
                schema=(REPO/'provider/sql/schema.sql').read_text()
                sql(schema)
                sql('ALTER TABLE clients DROP COLUMN api_token_hash; ALTER TABLE recovery_requests DROP COLUMN restore_public_key; ALTER TABLE ssh_keys DROP COLUMN restore_public_key;')
                runtime=root/'runtime'; (runtime/'.ssh').mkdir(parents=True); (runtime/'transfer').mkdir()
                nc=root/'nextcloud'; (nc/'config').mkdir(parents=True)
                source=root/'external-data'; source.mkdir(); (source/'file.txt').write_bytes(b'original external data\x00\xff')
                nc_config={'dbtype':'mysql','dbhost':'localhost:'+str(sock),'dbname':'bm_nextcloud','dbuser':'root','dbpassword':'','version':'34.0.0','datadirectory':str(source),'instanceid':'ocq1emgr4150','secret':'fixture-secret','passwordsalt':'fixture-salt'}
                (nc/'config/config.php').write_text('<?php $CONFIG = '+phpconfig.render(nc_config)+';')
                sql("CREATE TABLE example (value VARCHAR(40)); INSERT INTO example VALUES ('original database');",'bm_nextcloud')
                write=runtime/'.ssh/legacy'; run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(write)])
                old_private=write.read_bytes(); old_public=client_helpers.public_key(write)
                legacy=root/'legacy.conf'
                legacy.write_text('SOURCE_ID=ncdev.local-ocq1emgr4150\nSSH_KEY='+str(write)+'\nNC_PATH='+str(nc)+'\nDATA_PATH='+str(source)+'\nBACKUP_HOST=localhost\nBACKUP_PATH=/\n')
                cfg=config.legacy(legacy) | {'runtime':str(runtime),'client_id':'BM-000007','retention_days':36500}
                cfg=config.validate(cfg)
                allocation=root/'storage/BM-000007'; allocation.mkdir(parents=True)
                for name in ('data','config','database'):
                    (allocation/name).mkdir(); (allocation/name/'legacy').write_bytes(b'preserve '+name.encode())
                old_engine=JourneyEngine(cfg,DirectoryStorage(allocation))
                old_generation=old_engine.backup()
                original={str(p.relative_to(allocation)):p.read_bytes() for p in allocation.rglob('*') if p.is_file()}
                original_inode=allocation.stat().st_ino
                (root/'account/.ssh').mkdir(parents=True)
                authorized=root/'account/.ssh/authorized_keys'
                authorized.write_text(f'restrict,command="/usr/bin/rrsync -wo -munge {allocation}" {old_public} bm-client=BM-000007\n')
                host=root/'host'; run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(host)])
                with socket.socket() as listener:
                    listener.bind(('127.0.0.1',0)); port=listener.getsockname()[1]
                sshcfg=root/'sshd.conf'
                sshcfg.write_text(f'ListenAddress 127.0.0.1\nPort {port}\nHostKey {host}\nPidFile {root}/sshd.pid\nAuthorizedKeysFile {authorized}\nStrictModes no\nUsePAM no\nPasswordAuthentication no\nKbdInteractiveAuthentication no\nPubkeyAuthentication yes\nAllowUsers {account.pw_name}\nLogLevel ERROR\n')
                sshlog=(root/'ssh.log').open('w')
                sshd=subprocess.Popen([shutil.which('sshd'),'-D','-e','-f',str(sshcfg)],stdout=sshlog,stderr=sshlog)
                for _ in range(100):
                    if sshd.poll() is not None: self.fail((root/'ssh.log').read_text())
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                    except OSError: time.sleep(.05)
                cfg['ssh_port']=port
                config.atomic_json(root/'runtime.json',cfg)
                # A surviving legacy staged write key may have unsafe permissions.
                # ssh-keygen refuses it before a request reaches the provider.
                (runtime/'recovery').mkdir()
                staged_legacy=runtime/'recovery/id_ed25519'
                staged_legacy.write_bytes(old_private); staged_legacy.chmod(0o644)
                refused=subprocess.run(['ssh-keygen','-y','-f',str(staged_legacy)],capture_output=True)
                self.assertNotEqual(refused.returncode,0)
                self.assertIn(b'UNPROTECTED PRIVATE KEY FILE',refused.stderr)

                sql("INSERT INTO clients (client_id,source_id,source_url,approved_at,approved_by) VALUES ('BM-000007','ncdev.local-ocq1emgr4150','https://ncdev.example.test',UTC_TIMESTAMP(),'fixture');")
                sql(f"INSERT INTO storage_allocations (client_id,destination_type,storage_host,storage_port,storage_user,storage_path) VALUES ('BM-000007','ssh','localhost',{port},'backupstore','/var/lib/backupmanager-provider/BM-000007');")
                token_hash=hashlib.sha256(b'old-token').hexdigest()
                sql(f"INSERT INTO recovery_requests (recovery_request_id,request_token_hash,client_id,source_id,public_key,ssh_fingerprint,expires_at) VALUES ('REC-20260921-550904','{token_hash}','BM-000007','ncdev.local-ocq1emgr4150','{old_public}','old-fixture',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY));")
                sql(f"INSERT INTO provider_requests (request_id,request_token_hash,source_id,source_url,public_key,ssh_fingerprint,expires_at) VALUES ('REQ-20260921-000007','{token_hash}','ncdev.local-ocq1emgr4150','https://ncdev.example.test','{old_public}','old-fixture',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY));")
                sql(f"INSERT INTO ssh_keys (client_id,public_key,fingerprint) VALUES ('BM-000007','{old_public}','old-fixture');")
                allocation_before=sql('SELECT * FROM storage_allocations')
                app={'provider_url':'https://provider.example.test','provider_client_id':'BM-000007','provider_request_id':'REQ-20260921-000007','provider_request_token':'old-token','provider_request_status':'pending','recovery_request_id':'REC-20260921-550904','recovery_request_token':'old-token','recovery_request_status':'pending'}
                config.atomic_json(root/'appconfig.json',app)
                # Stage the actual installer twice with the legacy config. Never --apply.
                staged=root/'installed'; (staged/'etc/backupmanager').mkdir(parents=True)
                shutil.copyfile(legacy,staged/'etc/backupmanager/backupmanager.conf')
                for component in ('client','provider','client'):
                    run([sys.executable,str(REPO/'installer/install.py'),component,'--destdir',str(staged),'--nextcloud-root',str(nc)])
                self.assertEqual(json.loads((staged/'etc/backupmanager/runtime.json').read_text())['source_id'],cfg['source_id'])
                # Replay the real deployment gap: CLI install does not switch Apache/FPM.
                from test_web_deployment import web, fpm_fixture
                sites=staged/'etc/apache2/sites-enabled'; sites.mkdir(parents=True)
                pools=fpm_fixture(staged, '8.3')
                (sites/'provider.conf').write_text('DocumentRoot /var/www/backupmanager-provider/public\n')
                self.assertTrue(web.web_errors(staged))
                (sites/'provider.conf').write_text((staged/'opt/backupmanager-provider/apache.conf.example').read_text())
                (pools/'provider.conf').write_text((staged/'opt/backupmanager-provider/php-fpm.conf.example').read_text())
                self.assertEqual(web.web_errors(staged),[])

                with patch.object(client_helpers.pwd,'getpwnam',return_value=account): client_helpers.ensure_keys(cfg)
                self.assertEqual(write.read_bytes(),old_private)
                self.assertNotEqual(client_helpers.public_key(write),client_helpers.public_key(cfg['ssh_read_key']))
                tools=root/'bin'; tools.mkdir(); (root/'limits').mkdir()
                sudo=tools/'sudo'; sudo.write_text('#!/bin/sh\nexec '+sys.executable+' '+str(REPO/'tests/journey_runtime.py')+' "$@"\n'); sudo.chmod(0o755)
                env=dict(os.environ,BM_TEST_SOCKET=str(sock),BM_JOURNEY_ROOT=str(root),BM_JOURNEY_PORT=str(port),PATH=str(tools)+':'+os.environ['PATH'])
                run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(root/'https.key'),
                     '-out',str(root/'https.crt'),'-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'])
                class ProviderHTTPS(http.server.BaseHTTPRequestHandler):
                    def log_message(self, *args): pass
                    def do_GET(self): self.serve()
                    def do_POST(self): self.serve()
                    def serve(self):
                        body=self.rfile.read(int(self.headers.get('Content-Length','0'))).decode()
                        if (root/'provider-false200').exists():
                            fixture=json.loads((root/'provider-false200').read_text())
                            payload=fixture['body'].encode()
                            self.send_response(200); self.send_header('Content-Type',fixture['type'])
                            self.send_header('Content-Length',str(len(payload))); self.end_headers()
                            self.wfile.write(payload)
                            return
                        result=subprocess.run(['php',str(REPO/'tests/journey_provider.php')],env=env,
                            input=json.dumps({'method':self.command,'path':self.path,'body':body,
                                'token':self.headers.get('Authorization','')}).encode(),capture_output=True,timeout=30)
                        status=int((root/'http-status').read_text()) if result.returncode==0 else 500
                        with (root/'https-requests').open('a') as log: log.write(f'{self.command} {self.path} {status}\n')
                        self.send_response(status); self.send_header('Content-Type','application/json')
                        self.send_header('Content-Length',str(len(result.stdout))); self.end_headers()
                        self.wfile.write(result.stdout)
                https=http.server.HTTPServer(('127.0.0.1',0),ProviderHTTPS)
                tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(root/'https.crt',root/'https.key')
                https.socket=tls.wrap_socket(https.socket,server_side=True)
                env['BM_JOURNEY_HTTPS_PORT']=str(https.server_port)
                thread=threading.Thread(target=https.serve_forever,daemon=True); thread.start()
                provider=staged/'opt/backupmanager-provider'
                (provider/'config').mkdir(exist_ok=True)
                (root/'admin-sessions').mkdir()
                (root/'admin-password.hash').write_bytes(run(['php','-r','echo password_hash("fixture-admin-password", PASSWORD_DEFAULT);']).stdout)
                (root/'management-token').write_text('fixture-management-token-' + 'a'*40)
                provider_config={'database':{'host':'localhost;unix_socket='+str(sock),'name':'bm_journey','user':'root','password':''},
                    'admin':{'password_hash_file':str(root/'admin-password.hash')},
                    'api':{'management_token_file':str(root/'management-token')},
                    'storage':{'host':'backup.ncdev.local','port':port,'user':'backupstore','root':'/var/lib/backupmanager-provider'}}
                (staged/'etc/backupmanager-provider/config.php').write_text('<?php return '+phpconfig.render(provider_config)+';')
                (staged/'etc/backupmanager-provider/config.php').chmod(0o600)
                php=['php','-d','auto_prepend_file='+str(REPO/'tests/journey_boundary.php')]
                # Actual installed Config, Database and migration CLI; no DB class mock.
                run(php+[str(provider/'bin/migrate.php')],env=env)

                def route(name, values=None):
                    output=run(['php',str(REPO/'tests/journey_controller.php')],env=env,input=json.dumps({'route':name,'values':values or {}}).encode()).stdout
                    return json.loads(output)
                values={'clientId':'BM-000007','providerUrl':'https://provider.example.test','consent':'1'}
                denied=route('request-recovery',values | {'consent':'0'})
                self.assertEqual(denied['error_code'],'consent_required')
                self.assertEqual(sql('SELECT COUNT(*) FROM recovery_requests'),'1')
                sql("UPDATE recovery_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE recovery_request_id='REC-20260921-550904'")
                # Provider process uses Pacific/Honolulu; DB timestamps remain UTC.
                self.assertEqual(route('recovery-status')['status'],'expired')
                # Misconfigured HTTPS provider: real controller/client must reject false 200
                # without adding DB rows or retaining the legacy polling credentials.
                for content_type, body in [('text/html','<html>generic provider page</html>'),
                                           ('text/plain','<?php /* leaked front controller fixture */')]:
                    local=json.loads((root/'appconfig.json').read_text())
                    local.update(recovery_request_id='REC-20260921-550904', recovery_request_token='old-token')
                    config.atomic_json(root/'appconfig.json',local)
                    config.atomic_json(root/'provider-false200',{'type':content_type,'body':body})
                    failed=route('request-recovery',values)
                    self.assertEqual(failed['error_code'],'provider_invalid_response')
                    persisted=json.loads((root/'appconfig.json').read_text())
                    self.assertNotIn('recovery_request_id',persisted)
                    self.assertNotIn('recovery_request_token',persisted)
                    self.assertEqual(persisted['provider_client_id'],'BM-000007')
                    self.assertEqual(route('recovery-status')['error_code'],'provider_invalid_response')
                    self.assertEqual(sql('SELECT COUNT(*) FROM recovery_requests'),'1')
                    (root/'provider-false200').unlink()
                if os.environ.get('BM_BROWSER_TESTS') == '1':
                    browser=run(['node',str(REPO/'tests/recovery_browser.js')],env=env,cwd=REPO)
                    self.assertIn(b'browser recovery passed',browser.stdout)
                    request=json.loads((root/'browser-request.json').read_text())
                else:
                    request=route('request-recovery',values)
                self.assertTrue(request['success'],request)
                fresh=request['requestId']; self.assertNotEqual(fresh,'REC-20260921-550904')
                self.assertEqual(sql('SELECT COUNT(*) FROM recovery_requests'),'2')
                self.assertEqual(sql("SELECT status FROM recovery_requests WHERE recovery_request_id='REC-20260921-550904'"),'expired')
                pair=sql("SELECT public_key,restore_public_key FROM recovery_requests WHERE status='pending'").split('\t')
                self.assertEqual(len(pair),2); self.assertNotEqual(*pair)
                duplicate=route('request-recovery',values)
                self.assertEqual(duplicate['error_code'],'recovery_pending')
                self.assertEqual(sql('SELECT COUNT(*) FROM recovery_requests'),'2')
                self.assertIn('HTTP=409',(root/'application.log').read_text())
                self.assertEqual(route('provider-status')['status'],'stale')
                # Pending enrollment is explicitly rejected through management; this cannot rotate active credentials.
                sql("INSERT INTO provider_requests (request_id,request_token_hash,source_id,source_url,public_key,restore_public_key,ssh_fingerprint,expires_at) VALUES ('REQ-20260923-ABCDEF','"+('a'*64)+"','pending-enrollment','https://"+('long.'*30)+"example.test','"+pair[0]+"','"+pair[1]+"','fixture-enrollment',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))")
                sql("INSERT INTO provider_requests (request_id,request_token_hash,source_id,source_url,public_key,ssh_fingerprint,expires_at) VALUES ('REQ-20260921-B666A8','"+('b'*64)+"','expired-enrollment','https://old.example.test','"+old_public+"','fixture-old',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY))")
                inventory=route('provider-admin-requests')
                self.assertTrue(inventory['success'],inventory)
                stale=next(item for item in inventory['requests'] if item['request_id']=='REQ-20260921-B666A8')
                self.assertEqual(stale['status'],'expired'); self.assertFalse(stale['can_approve'])
                self.assertEqual(sql("SELECT status FROM provider_requests WHERE request_id='REQ-20260921-B666A8'"),'pending')
                current=next(item for item in inventory['requests'] if item['request_id']==fresh)
                self.assertEqual(current['type'],'recovery'); self.assertEqual(current['client_id'],'BM-000007')
                self.assertTrue(current['can_approve']); self.assertNotEqual(current['write_fingerprint'],current['read_fingerprint'])
                old=next(item for item in inventory['requests'] if item['request_id']=='REC-20260921-550904')
                self.assertEqual(old['status'],'expired'); self.assertFalse(old['can_approve']); self.assertFalse(old['can_reject'])
                # Actual standalone page uses one authenticated session and the same inventory.
                def admin_page(data):
                    result=run(['php',str(REPO/'tests/journey_admin.php')],env=env,input=json.dumps(data).encode())
                    return result.stdout.decode(),json.loads((root/'admin-http.json').read_text())
                html,login=admin_page({})
                self.assertIn('Administrator password',html); self.assertNotIn(fresh,html)
                _,signed_in=admin_page({'session':login['session'],'post':{'action':'login','csrf':login['csrf'],'password':'fixture-admin-password'}})
                html,session=admin_page({'session':signed_in['session']})
                self.assertIn(fresh,html)
                import re
                old_card=re.search(r'data-request-id="REQ-20260921-B666A8">(.*?)</article>',html,re.S).group(1)
                self.assertIn('expired',old_card); self.assertNotIn('<button',old_card)
                recovery_card=re.search('data-request-id="'+fresh+r'">(.*?)</article>',html,re.S).group(1)
                self.assertIn('value="approve"',recovery_card); self.assertIn('Read-key fingerprint',recovery_card)
                unchanged=authorized.read_bytes()
                html,_=admin_page({'session':signed_in['session'],'post':{'action':'approve','request_type':'recovery','request_id':fresh,'csrf':'wrong'}})
                self.assertIn('Invalid CSRF token',html); self.assertEqual(authorized.read_bytes(),unchanged)
                config_file=staged/'etc/backupmanager-provider/config.php'; valid_config=config_file.read_bytes()
                missing=dict(provider_config); missing['storage']=dict(provider_config['storage'],host='')
                config_file.write_text('<?php return '+phpconfig.render(missing)+';')
                blocked=route('provider-admin-action',{'requestType':'recovery','requestId':fresh,'action':'approve','confirm':'1'})
                self.assertEqual(blocked['error_code'],'storage_not_configured'); self.assertEqual(authorized.read_bytes(),unchanged)
                html,_=admin_page({'session':signed_in['session']})
                self.assertIn('Storage host is not configured',html); self.assertIn(fresh,html)
                config_file.write_bytes(valid_config)
                if os.environ.get('BM_BROWSER_TESTS') == '1':
                    browser=run(['node',str(REPO/'tests/provider_management_browser.js')],env=dict(env,BM_MANAGEMENT_REQUEST=fresh),cwd=REPO)
                    self.assertIn(b'provider management browser passed',browser.stdout)
                else:
                    original_keys=authorized.read_bytes()
                    rejected=route('provider-admin-action',{'requestType':'enrollment','requestId':'REQ-20260923-ABCDEF','action':'reject','confirm':'1'})
                    self.assertTrue(rejected['success'],rejected); self.assertEqual(authorized.read_bytes(),original_keys)
                    approval=route('provider-admin-action',{'requestType':'recovery','requestId':fresh,'action':'approve','confirm':'1'})
                    self.assertTrue(approval['success'],approval)
                self.assertEqual(sql("SELECT actor_id FROM provider_events WHERE event_type='recovery.approved' ORDER BY id DESC LIMIT 1"),'nextcloud:fixture-operator')
                self.assertEqual(sql('SELECT client_id FROM clients'),'BM-000007')
                replay_keys=authorized.read_bytes()
                replay=route('provider-admin-action',{'requestType':'recovery','requestId':fresh,'action':'approve','confirm':'1'})
                self.assertEqual(replay['error_code'],'request_not_pending'); self.assertEqual(authorized.read_bytes(),replay_keys)
                self.assertEqual(sql('SELECT * FROM storage_allocations'),allocation_before)
                text=authorized.read_text(); self.assertEqual(text.count('bm-client=BM-000007'),1); self.assertEqual(text.count('bm-restore=BM-000007'),1)
                self.assertIn(f'rrsync -wo -munge {allocation}',text); self.assertIn(f'rrsync -ro -munge {allocation}',text)
                run([str(sudo),'-n','/usr/local/sbin/backupmanager-install-authorized-keys'],env=env,input=json.dumps({'client_id':'BM-000007','write_key':pair[0],'read_key':pair[1]}).encode())
                self.assertEqual(authorized.read_text(),text)
                staged_read=runtime/'recovery/id_ed25519_restore'
                saved_read=staged_read.read_bytes()
                staged_read.write_bytes(write.read_bytes())
                failed_activation=route('recovery-status')
                self.assertFalse(failed_activation['success'])
                self.assertEqual(write.read_bytes(),old_private)
                self.assertEqual(json.loads((root/'appconfig.json').read_text())['recovery_request_id'],fresh)
                staged_read.write_bytes(saved_read)
                applied=route('recovery-status'); self.assertTrue(applied['success'],applied); self.assertEqual(applied['clientId'],'BM-000007')
                cfg=config.load(root/'runtime.json')
                self.assertEqual(cfg['ssh_host'], 'backup.ncdev.local')
                self.assertEqual(cfg['storage_profiles']['ssh_managed']['ssh_host'], 'backup.ncdev.local')
                self.assertEqual(cfg['credential_mode'], 'managed')
                app_state = json.loads((root/'appconfig.json').read_text())
                self.assertEqual(app_state['credential_mode'], 'managed')
                self.assertEqual(app_state['destination_type'], 'ssh')
                self.assertEqual([client_helpers.public_key(cfg[f]) for f in ('ssh_key','ssh_read_key')],pair)
                active_write=Path(cfg['ssh_key']).read_bytes()
                self.assertEqual(route('provider-status')['status'],'approved')
                self.assertEqual(route('recovery-status')['status'],'approved')
                self.assertEqual(Path(cfg['ssh_key']).read_bytes(),active_write)
                self.assertEqual(sql('SELECT client_id FROM clients'),'BM-000007')
                # Repair an already-approved legacy assignment via the real authenticated
                # endpoint/controller/helper, after the request ID has been consumed.
                key_bytes = {field: Path(cfg[field]).read_bytes() for field in ('ssh_key', 'ssh_read_key')}
                stale = config.remember_storage(cfg | {'ssh_host': 'localhost'})
                config.atomic_json(root/'runtime.json', stale)
                refreshed = route('refresh-provider')
                self.assertTrue(refreshed['success'], refreshed)
                cfg = config.load(root/'runtime.json')
                self.assertEqual(cfg['ssh_host'], 'backup.ncdev.local')
                self.assertEqual(cfg['storage_profiles']['ssh_managed']['ssh_host'], 'backup.ncdev.local')
                self.assertEqual({field: Path(cfg[field]).read_bytes() for field in key_bytes}, key_bytes)
                self.assertEqual(sql('SELECT * FROM storage_allocations'), allocation_before)
                self.assertEqual(authorized.read_bytes(), replay_keys)
                # The remaining transport checks use a private loopback SSH listener.
                provider_config['storage']['host'] = '127.0.0.1'
                config_file.write_text('<?php return '+phpconfig.render(provider_config)+';')
                self.assertTrue(route('refresh-provider')['success'])
                cfg = config.load(root/'runtime.json')
                # Only the fixture Unix username differs; use real SSH and both restrictions.
                class FixtureSSH(storage.SSH):
                    def remote(self, suffix): return super().remote(suffix).replace('backupstore@',account.pw_name+'@',1)
                transport=FixtureSSH(cfg)
                host_public=client_helpers.public_key(host)
                fingerprint='SHA256:'+base64.b64encode(hashlib.sha256(base64.b64decode(host_public.split()[1])).digest()).decode().rstrip('=')
                with patch.object(control,'backend',return_value=transport):
                    trusted=control.trust(cfg,{'key':host_public,'fingerprint':fingerprint})
                self.assertTrue(trusted['settings']['host_trusted'])
                self.assertIsNone(trusted['settings'].get('connection_test'))
                real=JourneyEngine(cfg,transport)
                states=[]
                original_record=control.record_connection_test
                def record(c,j): states.append(j['state']); original_record(c,j)
                def job(action, **fields):
                    with patch.object(control.subprocess,'run',return_value=subprocess.CompletedProcess([],0)):
                        created=control.enqueue(cfg,{'action':action,**fields})['job']
                    with patch.object(control,'load',return_value=cfg),patch.object(control,'Engine',return_value=real),patch.object(control,'record_connection_test',side_effect=record):
                        control.worker(created['id'])
                    return json.loads(control.job_path(cfg,created['id']).read_text())
                with patch.object(control.subprocess,'run',return_value=subprocess.CompletedProcess([],0)):
                    queued=control.enqueue(cfg,{'action':'test'})['job']
                control.dispatch('cancel',cfg,{'id':queued['id']})
                with patch.object(control,'load',return_value=cfg),patch.object(control,'Engine',return_value=real):
                    control.worker(queued['id'])
                self.assertEqual(json.loads(control.job_path(cfg,queued['id']).read_text())['error_code'],'cancelled')
                from backupmanager import finalize
                with patch.object(control.subprocess,'run',return_value=subprocess.CompletedProcess([],0)):
                    interrupted=control.enqueue(cfg,{'action':'test'})['job']
                with patch.object(finalize,'load',return_value=cfg):
                    finalize.finalize(interrupted['id'],'signal',exit_code='killed',exit_status='KILL')
                self.assertEqual(control.dispatch('settings',cfg,{})['settings']['connection_test']['error_code'],'interrupted')
                tested=job('test'); self.assertEqual(tested['state'],'completed',tested)
                self.assertEqual(tested['result'],{'connected':True}); self.assertIn('running',states)
                self.assertEqual(control.dispatch('settings',cfg,{})['settings']['connection_test']['state'],'completed')
                self.assertEqual(transport.inventory(),[old_generation])
                backed=job('backup'); self.assertEqual(backed['state'],'completed',backed)
                generation=backed['result']['generation']; self.assertNotEqual(generation,old_generation)
                self.assertEqual(set(transport.inventory()),{old_generation,generation})
                manifest=json.loads(transport.get(f'generations/{generation}/manifest.json'))
                self.assertEqual(set(manifest['artifacts']),{'config','data','database'})
                authorized.write_text('\n'.join(line for line in text.splitlines() if 'bm-restore=' in line)+'\n')
                # A real backup failure must retain its SSH root cause in dashboard state.
                failed_backup=job('backup')
                self.assertEqual(failed_backup['error_code'],'ssh_authentication')
                with patch.object(finalize,'load',return_value=cfg): finalize.finalize(failed_backup['id'],'exit-code')
                self.assertEqual(control.safe_status(cfg)['error_code'],'ssh_authentication')
                # Isolated disaster restore onto a different external datadir and DB.
                target=root/'restore-data'; target.mkdir(); (target/'unrelated').write_text('remove')
                sql('CREATE DATABASE bm_restore;', '')
                override=nc/'config/restore.config.php'
                override.write_text('<?php $CONFIG = '+phpconfig.render({'datadirectory':str(target),'dbname':'bm_restore'})+';')
                restored_cfg=config.validate(cfg | {'data_path':str(target)})
                restore_engine=JourneyEngine(restored_cfg,transport)
                # Remove write authorization: a complete restore needs only the read key.
                authorized.write_text('\n'.join(line for line in text.splitlines() if 'bm-restore=' in line)+'\n')
                before_restore={str(p.relative_to(allocation)):p.read_bytes() for p in allocation.rglob('*') if p.is_file()}
                get=transport.get
                def corrupt(key):
                    value=get(key)
                    return value+b'corruption' if '/data.' in key else value
                with patch.object(transport,'get',side_effect=corrupt), self.assertRaises((ValueError,RuntimeError)):
                    restore_engine.restore(generation,'disaster')
                self.assertEqual((target/'unrelated').read_text(),'remove')
                self.assertEqual({str(p.relative_to(allocation)):p.read_bytes() for p in allocation.rglob('*') if p.is_file()},before_restore)
                restore_engine.restore(generation,'disaster')
                self.assertEqual((target/'file.txt').read_bytes(),b'original external data\x00\xff')
                self.assertFalse((target/'unrelated').exists())
                self.assertEqual(sql('SELECT value FROM example','bm_restore'),'original database')
                self.assertEqual(phpconfig.read_config(nc)['datadirectory'],str(target))
                self.assertFalse((nc/'data').exists())
                self.assertEqual((source/'file.txt').read_bytes(),b'original external data\x00\xff')
                # Authentication failures and finalizer preserve their original cause.
                failed=job('test'); self.assertEqual(failed['error_code'],'ssh_authentication')
                from backupmanager import finalize
                with patch.object(finalize,'load',return_value=cfg): finalize.finalize(failed['id'],'exit-code')
                self.assertEqual(json.loads(control.job_path(cfg,failed['id']).read_text())['error_code'],'ssh_authentication')
                self.assertEqual(control.dispatch('settings',cfg,{})['settings']['connection_test']['state'],'failed')
                authorized.write_text(text)
                # Key content changes invalidate persisted successful probes.
                # Restore config is now on a new datadir; connection probes do not use it.
                self.assertEqual(job('test')['state'],'completed')
                keyfile=Path(cfg['ssh_read_key']); keybytes=keyfile.read_bytes(); keyfile.write_bytes(keybytes+b'\n')
                self.assertIsNone(control.dispatch('settings',cfg,{})['settings'].get('connection_test'))
                keyfile.write_bytes(keybytes)
                self.assertEqual(allocation.stat().st_ino,original_inode)
                for name,data in original.items(): self.assertEqual((allocation/name).read_bytes(),data,name)
                self.assertEqual(set(transport.inventory()),{old_generation,generation})
                self.assertFalse((root/'storage/BM-000008').exists())
                self.assertEqual(sql('SELECT client_id FROM clients'),'BM-000007')
                self.assertIn('POST /api/v1/recovery-requests 201',(root/'https-requests').read_text())
                self.assertIn('POST /api/v1/recovery-requests 409',(root/'https-requests').read_text())
                calls=(root/'helper-calls').read_text(); self.assertNotIn('remove-storage',calls)
                print('Legacy journey passed: upgrade -> fresh recovery -> approval -> real SSH pair -> activation -> host trust -> connection test -> backup -> inventory -> isolated SQL/data/config restore')
            finally:
                if https is not None:
                    https.shutdown(); https.server_close(); thread.join(timeout=5)
                if sshd is not None:
                    sshd.terminate(); sshd.wait(timeout=10); sshlog.close()
                db.terminate()
                try: db.wait(timeout=15)
                except subprocess.TimeoutExpired: db.kill(); db.wait()
