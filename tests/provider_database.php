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
require dirname(__DIR__) . '/provider/src/MigrationFailure.php';
require dirname(__DIR__) . '/provider/src/Migrations.php';
BackupManager\Provider\Migrations::run($pdo, dirname(__DIR__) . '/provider/sql/schema.sql');
BackupManager\Provider\Migrations::run($pdo, dirname(__DIR__) . '/provider/sql/schema.sql');
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
check((int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 1, 'Migration lost client rows');
check($pdo->query('SELECT api_token_hash FROM clients')->fetchColumn() === $oldToken, 'Legacy API credential was not migrated');
require __DIR__ . '/provider_migrations.php';
function fixtureKey(string $byte): string { return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat($byte, 32)); }
function cli(string $script, string $id, bool $success = true): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/provider_fixture_cli.php', $script, $id, 'fixture-admin'], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    check(($code === 0) === $success, 'Fixture CLI result unexpected for ' . $script . ': ' . $output);
}
function allocation(PDO $pdo, string $client): void {
    $rows = $pdo->query('SELECT * FROM storage_allocations WHERE client_id=' . $pdo->quote($client))->fetchAll(PDO::FETCH_ASSOC);
    check(count($rows) === 1, 'Expected one stable client allocation');
    check($rows[0]['storage_path'] === '/var/lib/backupmanager-provider/' . $client, 'Incorrect provider storage path');
    check($rows[0]['storage_user'] === 'backupstore', 'Restricted account changed');
}
function api(string $method, string $id, string $token, ?string $client): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/provider_fixture_api.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode(compact('method', 'id', 'token')));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'Fixture API failed: ' . $error);
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    check(!str_contains($output, '/var/lib/backupmanager-provider') && !str_contains($output, 'storage_path'), 'Provider filesystem path leaked to client');
    check(!str_contains($output, $token) && !str_contains($output, hash('sha256', $token)), 'Request credential leaked');
    if ($client === null) {
        check(!isset($result['connection']), 'Unapproved or unauthorized request received a connection');
    } else {
        check($result['success'] === true && $result['status'] === 'approved', 'Approved connection unavailable');
        check(array_intersect_key($result['connection'], array_flip(['client_id','host','port','user','path'])) === ['client_id' => $client, 'host' => 'storage.example.test', 'port' => 22, 'user' => 'backupstore', 'path' => '/'], 'Unexpected client connection fields or identity');
    }
}
$create = $pdo->prepare('INSERT INTO provider_requests (request_id, request_token_hash, status, source_id, source_url, public_key, restore_public_key, ssh_fingerprint, expires_at, requester_email) VALUES (?, ?, "pending", ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), ?)');
$create->execute(['REQ-20260101-000002', hash('sha256','fixture-new-token'), 'new-source', 'https://new.example.test', fixtureKey('a'), fixtureKey('b'), 'fixture-fingerprint-a', 'fixture@example.test']);
api('status', 'REQ-20260101-000002', 'fixture-new-token', null);
cli('approve.php', 'REQ-20260101-000002');
$client = $pdo->query('SELECT client_id FROM clients WHERE source_id="new-source"')->fetchColumn();
check(is_string($client), 'Approval did not create client');
allocation($pdo, $client);
api('status', 'REQ-20260101-000002', 'fixture-new-token', $client);
api('status', 'REQ-20260101-000002', 'wrong-fixture-token', null);
check($pdo->query('SELECT restore_public_key FROM ssh_keys WHERE client_id=' . $pdo->quote($client))->fetchColumn() === fixtureKey('b'), 'Approval did not persist read key');
$create->execute(['REQ-20260101-000003', hash('sha256','fixture-reenroll-token'), 'new-source', 'https://new.example.test', fixtureKey('a'), fixtureKey('b'), 'fixture-fingerprint-a', 'fixture@example.test']);
cli('approve.php', 'REQ-20260101-000003');
allocation($pdo, $client);
api('status', 'REQ-20260101-000003', 'fixture-reenroll-token', $client);
check((int)$pdo->query('SELECT COUNT(*) FROM clients WHERE source_id="new-source"')->fetchColumn() === 1, 'Reenrollment duplicated identity');
$create->execute(['REQ-20260101-000004', hash('sha256','fixture-reject-token'), 'reject-source', 'https://new.example.test', fixtureKey('c'), fixtureKey('d'), 'fixture-fingerprint-c', 'fixture@example.test']);
cli('reject-request.php', 'REQ-20260101-000004');
check($pdo->query('SELECT status FROM provider_requests WHERE request_id="REQ-20260101-000004"')->fetchColumn() === 'rejected', 'Rejection disappeared before client polling');
$recover = $pdo->prepare('INSERT INTO recovery_requests (recovery_request_id, request_token_hash, client_id, source_id, public_key, restore_public_key, ssh_fingerprint, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))');
$recover->execute(['REC-20260101-000001', hash('sha256','fixture-recovery-token'), $client, 'recovered-source', fixtureKey('e'), fixtureKey('f'), 'fixture-fingerprint-e']);
api('recoveryStatus', 'REC-20260101-000001', 'fixture-recovery-token', null);
cli('approve-recovery.php', 'REC-20260101-000001');
allocation($pdo, $client);
api('recoveryStatus', 'REC-20260101-000001', 'fixture-recovery-token', $client);
api('recoveryStatus', 'REC-20260101-000001', 'wrong-fixture-token', null);
$beforeKeys=$pdo->query('SELECT * FROM ssh_keys ORDER BY id')->fetchAll();
$beforeClients=$pdo->query('SELECT * FROM clients ORDER BY id')->fetchAll();
$recover->execute(['REC-20260101-CCCCCC', hash('sha256','fixture-collision-token'), $client, 'legacy-source', fixtureKey('k'), fixtureKey('l'), 'fixture-collision']);
cli('approve-recovery.php','REC-20260101-CCCCCC',false);
check($pdo->query('SELECT * FROM ssh_keys ORDER BY id')->fetchAll()===$beforeKeys,'Source collision changed credentials');
check($pdo->query('SELECT * FROM clients ORDER BY id')->fetchAll()===$beforeClients,'Recovery captured another source/client');
cli('approve-recovery.php','REQ-20260101-000002',false);
$pdo->exec("UPDATE recovery_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE recovery_request_id='REC-20260101-CCCCCC'");
cli('reject-recovery.php','REC-20260101-CCCCCC',false);
cli('approve-recovery.php','REC-20260101-CCCCCC',false);
check($pdo->query('SELECT * FROM ssh_keys ORDER BY id')->fetchAll()===$beforeKeys,'Expired action changed credentials');

check($pdo->query('SELECT api_token_hash FROM clients WHERE client_id=' . $pdo->quote($client))->fetchColumn() === hash('sha256','fixture-recovery-token'), 'Recovery did not rotate permanent API identity');
// Upgrade the exact legacy client shape: one write-only key, existing allocation,
// expired pending requests, fresh two-key recovery and the same permanent identity.
// BM-000007 and its allocation were seeded and preserved by the migration regression.
$oldKey = $pdo->prepare('INSERT INTO ssh_keys (client_id, public_key, fingerprint, status) VALUES (?, ?, ?, "active")');
$oldKey->execute(['BM-000007', fixtureKey('g'), 'legacy-BM-000007']);
$recover->execute(['REC-20260101-000007', hash('sha256','expired-token'), 'BM-000007', 'ncdev-legacy', fixtureKey('h'), fixtureKey('i'), 'expired-fingerprint']);
$pdo->exec('UPDATE recovery_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE recovery_request_id="REC-20260101-000007"');
function createRequest(string $method, array $body): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/provider_fixture_api.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode(compact('method','body'))); fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process)===0, 'Create API fixture failed: '.$error);
    return json_decode($output,true,512,JSON_THROW_ON_ERROR);
}
$body=['client_id'=>'BM-000007','source_id'=>'ncdev-legacy','public_key'=>fixtureKey('h'),'restore_public_key'=>fixtureKey('i')];
$fresh=createRequest('createRecovery',$body);
check(($fresh['success']??false) && $fresh['recovery_request_id']!=='REC-20260101-000007', 'Expired recovery blocked a fresh request');
check($pdo->query('SELECT status FROM recovery_requests WHERE recovery_request_id="REC-20260101-000007"')->fetchColumn()==='expired', 'Expired pending recovery not persisted');
check(!createRequest('createRecovery',$body)['success'], 'Duplicate live recovery was allowed');
cli('approve-recovery.php',$fresh['recovery_request_id']);
allocation($pdo,'BM-000007');
check($pdo->query('SELECT COUNT(*) FROM clients WHERE client_id="BM-000007"')->fetchColumn()==1, 'Recovery replaced client ID');
check($pdo->query('SELECT public_key FROM ssh_keys WHERE client_id="BM-000007" AND status="active"')->fetchColumn()===fixtureKey('h'), 'Recovery did not replace legacy write key');
check($pdo->query('SELECT restore_public_key FROM ssh_keys WHERE client_id="BM-000007" AND status="active"')->fetchColumn()===fixtureKey('i'), 'Recovery did not install distinct read key');
api('recoveryStatus',$fresh['recovery_request_id'],$fresh['request_token'],'BM-000007');
// Exact live regression: canonical config changes while the old allocation remains localhost.
$pdo->exec('UPDATE storage_allocations SET storage_host="localhost" WHERE client_id="BM-000007"');
$unchangedAllocation = $pdo->query('SELECT * FROM storage_allocations WHERE client_id="BM-000007"')->fetchAll();
$unchangedKeys = $pdo->query('SELECT * FROM ssh_keys WHERE client_id="BM-000007"')->fetchAll();
putenv('BM_TEST_STORAGE_HOST=backup.ncdev.local');
foreach ([['recoveryStatus', $fresh['recovery_request_id']], ['clientConnection', 'BM-000007']] as [$method, $id]) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/provider_fixture_api.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode(['method'=>$method,'id'=>$id,'token'=>$fresh['request_token']])); fclose($pipes[0]);
    $response = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
    $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, $error);
    check($response['connection']['host'] === 'backup.ncdev.local'
        && $response['connection']['client_id'] === 'BM-000007'
        && $response['connection']['port'] === 22 && $response['connection']['user'] === 'backupstore'
        && $response['connection']['path'] === '/', 'Legacy allocation overrode canonical endpoint');
}
api('clientConnection', 'BM-000007', 'wrong-token', null);
check($pdo->query('SELECT * FROM storage_allocations WHERE client_id="BM-000007"')->fetchAll() === $unchangedAllocation, 'Refresh changed allocation');
check($pdo->query('SELECT * FROM ssh_keys WHERE client_id="BM-000007"')->fetchAll() === $unchangedKeys, 'Refresh changed approved keys');
putenv('BM_TEST_STORAGE_HOST');
// A retry after a lost client activation response can reuse the same staged pair.
$retry=createRequest('createRecovery',$body);
check(($retry['success']??false), 'Repeated recovery did not create a new request');
cli('approve-recovery.php',$retry['recovery_request_id']);
// An older approved token must not return a connection after recovery rotation.
api('recoveryStatus',$fresh['recovery_request_id'],$fresh['request_token'],null);
allocation($pdo,'BM-000007');
check($pdo->query('SELECT COUNT(*) FROM ssh_keys WHERE client_id="BM-000007" AND status="active"')->fetchColumn()==1,'Repeated recovery duplicated active write identity');
// A legacy pending enrollment with no read key must not occupy the unique pending-source slot.
$create->execute(['REQ-20260101-000007',hash('sha256','legacy-pending'),'legacy-pending','https://ncdev.example.test',fixtureKey('j'),null,'legacy-pending-fingerprint','fixture@example.test']);
$newEnrollment=createRequest('create',['source_id'=>'legacy-pending','source_url'=>'https://ncdev.example.test','requester_email'=>'fixture@example.test','public_key'=>fixtureKey('j'),'restore_public_key'=>fixtureKey('k')]);
check(($newEnrollment['success']??false), 'Legacy write-only pending enrollment blocked new keys');
check($pdo->query('SELECT status FROM provider_requests WHERE request_id="REQ-20260101-000007"')->fetchColumn()==='expired','Legacy pending request retained unique slot');
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
