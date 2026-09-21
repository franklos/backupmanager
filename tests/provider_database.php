<?php
declare(strict_types=1);
$socket = getenv('BM_TEST_SOCKET');
if (!is_string($socket) || !preg_match('#^/tmp/bm-integration-[A-Za-z0-9_-]+/server.sock$#D', $socket)) { exit(2); }
$pdo = new PDO('mysql:unix_socket=' . $socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE bm_fixture');
$pdo->exec('USE bm_fixture');
$schema = file_get_contents(dirname(__DIR__) . '/provider/sql/schema.sql');
$legacy = preg_replace('/^\s*`(?:api_token_hash|restore_public_key)`[^\n]*\n/m', '', $schema);
foreach (explode(';', $legacy) as $sql) { if (trim($sql) !== '') { $pdo->exec($sql); } }
$pdo->exec('DROP TABLE deletion_requests'); // Private fixture only: simulate the old baseline omission.
$pdo->exec("INSERT INTO clients (client_id, source_id, source_url, approved_at, approved_by) VALUES ('BM-000001', 'legacy-source', 'https://legacy.example.test', UTC_TIMESTAMP(), 'fixture')");
$oldToken = hash('sha256', 'fixture-old-token');
$insert = $pdo->prepare('INSERT INTO provider_requests (request_id, request_token_hash, status, source_id, source_url, public_key, ssh_fingerprint) VALUES (?, ?, "approved", ?, ?, ?, ?)');
$insert->execute(['REQ-20260101-000001', $oldToken, 'legacy-source', 'https://legacy.example.test', 'legacy fixture key', 'fixture-fingerprint']);
require dirname(__DIR__) . '/provider/src/Migrations.php';
BackupManager\Provider\Migrations::run($pdo, dirname(__DIR__) . '/provider/sql/schema.sql');
BackupManager\Provider\Migrations::run($pdo, dirname(__DIR__) . '/provider/sql/schema.sql');
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
check((int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 1, 'Migration lost client rows');
check($pdo->query('SELECT api_token_hash FROM clients')->fetchColumn() === $oldToken, 'Legacy API credential was not migrated');
function fixtureKey(string $byte): string { return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat($byte, 32)); }
function cli(string $script, string $id, bool $success = true): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/provider_fixture_cli.php', $script, $id, 'fixture-admin'], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    check(($code === 0) === $success, 'Fixture CLI result unexpected for ' . $script . ': ' . $output);
}
$create = $pdo->prepare('INSERT INTO provider_requests (request_id, request_token_hash, status, source_id, source_url, public_key, restore_public_key, ssh_fingerprint, expires_at, requester_email) VALUES (?, ?, "pending", ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), ?)');
$create->execute(['REQ-20260101-000002', hash('sha256','fixture-new-token'), 'new-source', 'https://new.example.test', fixtureKey('a'), fixtureKey('b'), 'fixture-fingerprint-a', 'fixture@example.test']);
cli('approve.php', 'REQ-20260101-000002');
$client = $pdo->query('SELECT client_id FROM clients WHERE source_id="new-source"')->fetchColumn();
check(is_string($client), 'Approval did not create client');
check($pdo->query('SELECT restore_public_key FROM ssh_keys WHERE client_id=' . $pdo->quote($client))->fetchColumn() === fixtureKey('b'), 'Approval did not persist read key');
$create->execute(['REQ-20260101-000003', hash('sha256','fixture-reenroll-token'), 'new-source', 'https://new.example.test', fixtureKey('a'), fixtureKey('b'), 'fixture-fingerprint-a', 'fixture@example.test']);
cli('approve.php', 'REQ-20260101-000003');
check((int)$pdo->query('SELECT COUNT(*) FROM clients WHERE source_id="new-source"')->fetchColumn() === 1, 'Reenrollment duplicated identity');
$create->execute(['REQ-20260101-000004', hash('sha256','fixture-reject-token'), 'reject-source', 'https://new.example.test', fixtureKey('c'), fixtureKey('d'), 'fixture-fingerprint-c', 'fixture@example.test']);
cli('reject-request.php', 'REQ-20260101-000004');
check($pdo->query('SELECT status FROM provider_requests WHERE request_id="REQ-20260101-000004"')->fetchColumn() === 'rejected', 'Rejection disappeared before client polling');
$recover = $pdo->prepare('INSERT INTO recovery_requests (recovery_request_id, request_token_hash, client_id, source_id, public_key, restore_public_key, ssh_fingerprint, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))');
$recover->execute(['REC-20260101-000001', hash('sha256','fixture-recovery-token'), $client, 'recovered-source', fixtureKey('e'), fixtureKey('f'), 'fixture-fingerprint-e']);
cli('approve-recovery.php', 'REC-20260101-000001');
check($pdo->query('SELECT api_token_hash FROM clients WHERE client_id=' . $pdo->quote($client))->fetchColumn() === hash('sha256','fixture-recovery-token'), 'Recovery did not rotate permanent API identity');
$delete = $pdo->prepare('INSERT INTO deletion_requests (deletion_request_id, client_id, delete_storage) VALUES (?, ?, 1)');
$delete->execute(['DEL-20260101-000001', $client]);
$failure = dirname($socket) . '/fail-helper';
file_put_contents($failure, 'backupmanager-remove-storage');
cli('approve-deletion.php', 'DEL-20260101-000001', false);
check($pdo->query('SELECT status FROM clients WHERE client_id=' . $pdo->quote($client))->fetchColumn() === 'suspended', 'Failed deletion left client active');
check($pdo->query('SELECT status FROM deletion_requests WHERE deletion_request_id="DEL-20260101-000001"')->fetchColumn() === 'failed', 'Failed deletion not retryable');
unlink($failure);
cli('approve-deletion.php', 'DEL-20260101-000001');
check($pdo->query('SELECT status FROM clients WHERE client_id=' . $pdo->quote($client))->fetchColumn() === 'terminated', 'Deletion retry did not finish');
check((int)$pdo->query('SELECT COUNT(*) FROM clients WHERE client_id="BM-000001"')->fetchColumn() === 1, 'Unrelated client changed');
echo "Isolated MariaDB migration, approval, reenrollment, recovery and deletion retry tests passed.\n";
