"""Root-cause preservation through engine, worker, cleanup and systemd finalization."""
import hashlib
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import test_runtime
from test_runtime import FixtureEngine, MemoryStorage, config, control, engine, storage
from backupmanager import finalize
from backupmanager.errors import Failure


class FailureTests(unittest.TestCase):
    setUp = test_runtime.LifecycleTests.setUp

    def job(self, action='backup', state='queued'):
        identity = 'a' * 32
        file = Path(self.cfg['runtime']) / 'jobs' / (identity + '.json')
        config.atomic_json(file, {'id': identity, 'action': action, 'state': state,
            'generation': '20260101T000000Z-abcdef123456', 'type': 'data',
            'configuration_hash': control.configuration_hash(self.cfg)})
        return identity, file

    def finalize(self, identity='backup', result='exit-code', **kwargs):
        with patch.object(finalize, 'load', return_value=self.cfg):
            finalize.finalize(identity, result, **kwargs)

    def worker(self, identity):
        with patch.object(control, 'load', return_value=self.cfg), patch.object(control, 'Engine', return_value=self.engine):
            control.worker(identity)

    def test_ssh_authentication_survives_engine_and_scheduled_finalizer(self):
        ssh = storage.SSH(self.cfg | {'ssh_host': 'fixture'}, run=lambda *a, **k:
            subprocess.CompletedProcess(a[0], 255, b'', b'backupstore@fixture: Permission denied (publickey).'))
        self.store.put = lambda *args: ssh.rsync([])
        with self.assertRaisesRegex(Failure, 'SSH authentication failed'):
            self.engine.backup()
        before = control.safe_status(self.cfg)
        self.finalize()
        after = control.safe_status(self.cfg)
        self.assertEqual(after['error_code'], 'ssh_authentication')
        self.assertEqual(after['error'], before['error'])
        self.assertNotIn('interrupted', after['error'].lower())
        self.assertEqual(after['finalizer']['service_result'], 'exit-code')

    def test_worker_and_status_share_root_cause_after_finalizer(self):
        identity, file = self.job()
        self.store.put = lambda *args: (_ for _ in ()).throw(Failure('ssh_authentication'))
        self.worker(identity)
        self.finalize(identity)
        job = json.loads(file.read_text())
        status = control.safe_status(self.cfg)
        for key in ('error', 'error_code'):
            self.assertEqual(job[key], status[key])
        self.assertEqual(job['error_code'], 'ssh_authentication')

    def test_specific_legacy_errors_are_never_replaced(self):
        for error in ['SSH authentication failed', 'Host key verification failed', 'rsync failed',
                      'Database dump failed', 'S3 API denied', 'Storage permission denied', 'Operation cancelled']:
            self.engine.status(state='failed', error=error)
            self.finalize()
            self.assertEqual(control.safe_status(self.cfg)['error'], error)

    def test_real_interruption_and_service_termination_are_distinct(self):
        for result, exit_status, cause in [('signal', 'KILL', 'interrupted'), ('signal', 'TERM', 'service_terminated'),
                                         ('timeout', 'TERM', 'service_timeout'), ('exit-code', '1', 'operation_failed')]:
            self.engine.status(state='running', error='', error_code='')
            self.finalize(result=result, exit_code='exited' if result == 'exit-code' else 'killed', exit_status=exit_status)
            self.assertEqual(control.safe_status(self.cfg)['error_code'], cause)

    def test_interrupted_restore_keeps_maintenance_and_marks_job(self):
        identity, file = self.job('restore', 'restoring')
        self.engine.maintenance(True)
        self.engine.status(state='restoring', error='')
        self.finalize(identity, 'signal', exit_status='KILL')
        self.assertEqual(control.safe_status(self.cfg)['state'], 'maintenance_required')
        self.assertEqual(json.loads(file.read_text())['error_code'], 'interrupted')
        self.assertTrue(self.engine.nc_config()['maintenance'])

    def test_operator_cancellation_does_not_corrupt_last_backup(self):
        self.engine.backup()
        before = control.safe_status(self.cfg)
        identity, file = self.job('restore')
        file.with_suffix('.cancel').touch()
        self.worker(identity)
        self.finalize(identity, 'signal', exit_status='TERM')
        job = json.loads(file.read_text())
        self.assertEqual(job['state'], 'cancelled')
        self.assertEqual(job['error_code'], 'cancelled')
        self.assertEqual(control.safe_status(self.cfg), before)

    def test_cancelled_before_worker_is_also_recognized(self):
        identity, file = self.job('verify')
        file.with_suffix('.cancel').touch()
        self.finalize(identity, 'signal')
        self.assertEqual(json.loads(file.read_text())['error_code'], 'cancelled')

    def test_connection_cleanup_cannot_replace_authentication_failure(self):
        identity, file = self.job('test')
        self.store.put = lambda *args: (_ for _ in ()).throw(Failure('ssh_authentication'))
        self.store.delete_generation = lambda *args: (_ for _ in ()).throw(Failure('storage_permission'))
        self.worker(identity)
        self.finalize(identity)
        job = json.loads(file.read_text())
        self.assertEqual(job['error_code'], 'ssh_authentication')
        self.assertEqual(job['cleanup_error']['error_code'], 'storage_permission')
        self.assertEqual(control.safe_status(self.cfg)['state'], 'missing')

    def test_cleanup_only_failure_is_distinct(self):
        identity, file = self.job('test')
        self.store.delete_generation = lambda *args: (_ for _ in ()).throw(Failure('storage_permission'))
        self.worker(identity)
        job = json.loads(file.read_text())
        self.assertEqual(job['error_code'], 'cleanup_failed')
        self.assertEqual(job['state'], 'failed')

    def test_successful_connection_checks_both_keys_and_preserves_existing_points(self):
        self.engine.backup()
        objects = dict(self.store.objects)
        identity, file = self.job('test')
        self.worker(identity)
        job = json.loads(file.read_text())
        self.assertEqual(job['state'], 'completed')
        self.assertTrue(job['result']['connected'])
        self.assertEqual(self.store.objects, objects)
        settings = control.dispatch('settings', self.cfg, {})['settings']
        self.assertEqual(settings['connection_test']['id'], identity)
        self.assertNotIn('connection_test', control.dispatch('settings', self.cfg | {'stale_hours': self.cfg['stale_hours'] + 1}, {})['settings'])

    def test_enqueue_failure_becomes_terminal(self):
        with patch.object(control.subprocess, 'run', side_effect=subprocess.CalledProcessError(1, ['systemctl'])):
            with self.assertRaises(Failure):
                control.enqueue(self.cfg, {'action': 'test'})
        jobs = list((Path(self.cfg['runtime']) / 'jobs').glob('*.json'))
        self.assertEqual(len(jobs), 1)
        self.assertEqual(json.loads(jobs[0].read_text())['error_code'], 'job_start_failed')

    def test_transport_classifies_causes(self):
        for text, cause in [(b'Host key verification failed.', 'ssh_host_verification'),
                            (b'Permission denied (publickey,password).', 'ssh_authentication'),
                            (b'rsync: mkdir Permission denied', 'storage_permission'),
                            (b'rsync: protocol error', 'rsync_failed')]:
            ssh = storage.SSH(self.cfg | {'ssh_host': 'fixture'}, run=lambda *a, **k: subprocess.CompletedProcess(a[0], 1, b'', text))
            with self.assertRaises(Failure) as raised:
                ssh.rsync([])
            self.assertEqual(raised.exception.code, cause)

    def test_database_dump_failure_preserved_without_maintenance_cleanup(self):
        self.engine.dump = lambda *args: (_ for _ in ()).throw(RuntimeError('Database dump failed'))
        with patch.object(self.engine, 'maintenance', side_effect=AssertionError('Backup toggled maintenance')):
            with self.assertRaisesRegex(RuntimeError, 'Database dump failed'):
                self.engine.backup()
        self.finalize()
        status = control.safe_status(self.cfg)
        self.assertEqual(status['error_code'], 'database_dump')
        self.assertEqual(status['state'], 'failed')
        self.assertIsNone(status['cleanup_error'])
        self.assertFalse(self.engine.nc_config().get('maintenance', False))

    def test_connection_interruption_replaces_previous_successful_test(self):
        identity, file = self.job('test', 'running')
        record = json.loads(file.read_text())
        control.record_connection_test(self.cfg, record | {'state':'completed','result':{'connected':True}})
        self.finalize(identity, 'signal', exit_status='KILL')
        settings = control.dispatch('settings', self.cfg, {})['settings']
        self.assertEqual(settings['connection_test']['state'], 'failed')
        self.assertEqual(settings['connection_test']['error_code'], 'interrupted')
        self.assertEqual(control.safe_status(self.cfg)['state'], 'missing')

    def test_key_rotation_invalidates_connection_success(self):
        key=Path(self.cfg['runtime'])/'key'
        key.write_text('old-private-fixture')
        self.cfg['ssh_key']=str(key)
        identity, file=self.job('test')
        self.worker(identity)
        self.assertIn('connection_test',control.dispatch('settings',self.cfg,{})['settings'])
        key.write_text('new-private-fixture')
        self.assertNotIn('connection_test',control.dispatch('settings',self.cfg,{})['settings'])

    def test_diagnostic_survives_finalization_and_is_separate_from_primary_message(self):
        self.engine.dump=lambda *args: (_ for _ in ()).throw(RuntimeError('Database dump failed: fixture detail'))
        with self.assertRaises(RuntimeError): self.engine.backup()
        self.finalize()
        record=control.safe_status(self.cfg)
        self.assertEqual(record['error_code'],'database_dump')
        self.assertEqual(record['error_detail'],'Database dump failed: fixture detail')
        self.assertEqual(record['error'],'Database dump failed.')

    def test_cleanup_context_failure_does_not_overwrite_root_error(self):
        self.engine.status(state='failed',error='SSH authentication failed.',error_code='ssh_authentication')
        with patch.object(finalize, 'parse', side_effect=ValueError('unreadable configuration')):
            self.finalize()
        record=control.safe_status(self.cfg)
        self.assertEqual(record['error_code'],'ssh_authentication')
        self.assertTrue(record['finalizer']['maintenance_check_failed'])
        self.assertEqual(record['state'],'maintenance_required')

    def test_systemd_clean_signal_stop_is_not_successful_job_completion(self):
        identity,file=self.job('test','running')
        self.finalize(identity,'success',exit_code='killed',exit_status='TERM')
        job=json.loads(file.read_text())
        self.assertEqual(job['state'],'failed')
        self.assertEqual(job['error_code'],'service_terminated')
        self.assertEqual(control.dispatch('settings',self.cfg,{})['settings']['connection_test']['state'],'failed')
