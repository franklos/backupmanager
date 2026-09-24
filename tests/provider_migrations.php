<?php
// Loaded by provider_database.php against its private MariaDB instance only.
use BackupManager\Provider\Migrations;
use BackupManager\Provider\MigrationFailure;
$schemaFile = dirname(__DIR__) . '/provider/sql/schema.sql';
$pdo->exec("CREATE USER 'bm_runtime'@'localhost' IDENTIFIED BY 'fixture-private-password'");
$pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON bm_fixture.* TO 'bm_runtime'@'localhost'");
$runtime = new PDO('mysql:unix_socket=' . $socket . ';dbname=bm_fixture', 'bm_runtime', 'fixture-private-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
check(Migrations::run($runtime, $schemaFile, true) === [], 'CRUD-only check is not a no-op');
check(Migrations::run($runtime, $schemaFile) === [], 'CRUD-only migration is not a no-op');
// Prove the old unconditional IF NOT EXISTS call fails with the same account.
try { $runtime->exec('CREATE TABLE IF NOT EXISTS clients (id INT)'); throw new RuntimeException('Fixture unexpectedly has CREATE'); }
catch (PDOException $error) { check((int)$error->errorInfo[1] === 1142, 'Unexpected fixture privilege error'); }
function migrationFailure(PDO $db, string $expected, bool $checkOnly = false): string {
    global $schemaFile;
    try { Migrations::run($db, $schemaFile, $checkOnly); }
    catch (MigrationFailure $error) {
        check(str_contains($error->getMessage(), $expected), 'Unexpected migration diagnostic: ' . $error->getMessage());
        check(!str_contains($error->getMessage(), 'fixture-private-password'), 'Credential leaked');
        return $error->getMessage();
    }
    throw new RuntimeException('Expected migration failure: ' . $expected);
}
// Missing column: read-only plan, targeted denial, privileged apply, CRUD-only retry.
$pdo->exec('ALTER TABLE recovery_requests DROP COLUMN restore_public_key');
$plan = Migrations::run($runtime, $schemaFile, true);
check(count($plan) === 1 && $plan[0]['id'] === 'add column recovery_requests.restore_public_key' && $plan[0]['privilege'] === 'ALTER', 'Incorrect column plan');
$message = migrationFailure($runtime, 'Privilege/authentication failure');
check(str_contains($message, $plan[0]['id']) && str_contains($message, 'required privilege: ALTER') && str_contains($message, 'SQLSTATE=42000'), 'Missing operation/privilege/error code');
check((int)$pdo->query("SELECT IS_FREE_LOCK('backupmanager-migration')")->fetchColumn() === 1, 'Failure leaked migration lock');
Migrations::run($pdo, $schemaFile);
check(Migrations::run($runtime, $schemaFile) === [], 'Column retry not idempotent');
$pdo->exec('DROP TABLE deletion_requests');
$plan = Migrations::run($runtime, $schemaFile, true);
check(count($plan) === 1 && $plan[0]['id'] === 'create table deletion_requests' && str_starts_with($plan[0]['privilege'], 'CREATE'), 'Incorrect table plan');
migrationFailure($runtime, 'create table deletion_requests');
Migrations::run($pdo, $schemaFile);
$pdo->exec('DROP INDEX uq_provider_requests_pending_source_id ON provider_requests');
$plan = Migrations::run($runtime, $schemaFile, true);
check(count($plan) === 1 && $plan[0]['privilege'] === 'INDEX', 'Incorrect index privilege');
migrationFailure($runtime, 'required privilege: INDEX');
Migrations::run($pdo, $schemaFile);
// Same-name index must not mask the wrong definition.
$pdo->exec('DROP INDEX uq_clients_source_id ON clients');
$pdo->exec('CREATE INDEX uq_clients_source_id ON clients (source_id)');
migrationFailure($runtime, 'Schema conflict: clients.uq_clients_source_id', true);
$pdo->exec('DROP INDEX uq_clients_source_id ON clients');
$pdo->exec("INSERT INTO clients (client_id, source_id, source_url, approved_at, approved_by) VALUES ('BM-999999', 'legacy-source', 'secret-record-value', UTC_TIMESTAMP(), 'fixture')");
$message = migrationFailure($runtime, 'Duplicate legacy data', true);
check(str_contains($message, 'clients.uq_clients_source_id') && !str_contains($message, 'legacy-source') && !str_contains($message, 'secret-record-value'), 'Duplicate diagnostic leaked values or omitted index');
check((int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 2, 'Duplicate detection mutated records');
$pdo->exec("DELETE FROM clients WHERE client_id='BM-999999'");
Migrations::run($pdo, $schemaFile);
$pdo->exec('ALTER TABLE recovery_requests MODIFY restore_public_key VARCHAR(255) DEFAULT NULL');
migrationFailure($runtime, 'Schema conflict: recovery_requests.restore_public_key', true);
$pdo->exec('ALTER TABLE recovery_requests MODIFY restore_public_key TEXT DEFAULT NULL');
$pdo->exec('DROP INDEX uq_provider_requests_pending_source_id ON provider_requests');
$pdo->exec('ALTER TABLE provider_requests DROP COLUMN pending_source_id');
$pdo->exec("ALTER TABLE provider_requests ADD pending_source_id VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN status='approved' THEN source_id ELSE NULL END) STORED");
migrationFailure($runtime, 'incompatible generated expression', true);
$pdo->exec('ALTER TABLE provider_requests DROP COLUMN pending_source_id');
$pdo->exec("ALTER TABLE provider_requests ADD pending_source_id VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN status='pend ing' THEN source_id ELSE NULL END) STORED");
migrationFailure($runtime, 'incompatible generated expression', true);
$pdo->exec('ALTER TABLE provider_requests DROP COLUMN pending_source_id');
Migrations::run($pdo, $schemaFile);
// A pending legacy data conversion is visible, read-only in --check, and converges.
$pdo->exec("UPDATE provider_requests SET approval_token_hash=REPEAT('a',64), approval_token_expires_at=UTC_TIMESTAMP()");
$plan = Migrations::run($runtime, $schemaFile, true);
check(count($plan) === 1 && $plan[0]['privilege'] === 'UPDATE', 'Legacy token conversion not planned');
check($pdo->query('SELECT approval_token_hash FROM provider_requests')->fetchColumn() === str_repeat('a',64), 'Check mode wrote data');
Migrations::run($runtime, $schemaFile);
check(Migrations::run($runtime, $schemaFile) === [], 'Legacy data conversion did not converge');
foreach ([1062 => 'Duplicate legacy data', 1064 => 'SQL failure', 1060 => 'Schema conflict', 1142 => 'Privilege/authentication failure'] as $number => $label) {
    $error = new PDOException('secret-record-value fixture-private-password');
    $error->errorInfo = ['42000', $number, 'secret-record-value'];
    $message = Migrations::describe($error, 'fixture operation', 'ALTER');
    check(str_contains($message, $label) && str_contains($message, "driver=$number") && !str_contains($message, 'secret-record-value') && !str_contains($message, 'fixture-private-password'), 'SQL error classification/redaction failed');
}
// Empty database is planned without writing and created in foreign-key dependency order.
$pdo->exec('CREATE DATABASE bm_fresh');
$pdo->exec('USE bm_fresh');
check(count(Migrations::run($pdo, $schemaFile, true)) === 7, 'Fresh schema plan incomplete');
check($pdo->query('SHOW TABLES')->fetchAll() === [], 'Fresh check created tables');
Migrations::run($pdo, $schemaFile);
check(Migrations::run($pdo, $schemaFile) === [], 'Fresh schema retry failed');
$pdo->exec('USE bm_fixture');

function migrationCli(array $args, int $expected, string $message): string {
    $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/provider_migration_bootstrap.php',
        dirname(__DIR__) . '/provider/bin/migrate.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === $expected && str_contains($output, $message), 'Unexpected CLI result: ' . $output);
    check(!str_contains($output, 'fixture-private-password'), 'CLI leaked password');
    return $output;
}
migrationCli(['--check'], 0, 'no changes required');
migrationCli([], 0, 'no changes required');
// Exact ncdev CLI regression: current schema, CRUD-only SQL account, no flags.
$snapshot = static function () use ($pdo): array {
    $result = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $result[$table] = [
            $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],
            $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
    return $result;
};
$beforeNoop = $snapshot();
$beforeGrants = $runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
$noopOutput = migrationCli([], 0, 'Schema and data migrations are up to date; no changes required.');
check(!str_contains($noopOutput, 'Pending:'), 'Current schema reported a pending operation');
check($snapshot() === $beforeNoop, 'CRUD-only CLI no-op changed schema or data');
check($runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) === $beforeGrants, 'CLI changed runtime grants');
check((int)$pdo->query("SELECT IS_FREE_LOCK('backupmanager-migration')")->fetchColumn() === 1, 'CLI no-op leaked migration lock');
foreach ([
    ['DROP TABLE deletion_requests', 'create table deletion_requests', 'CREATE'],
    ['DROP INDEX uq_provider_requests_pending_source_id ON provider_requests', 'create unique index provider_requests.uq_provider_requests_pending_source_id on pending_source_id', 'INDEX'],
] as [$remove, $operation, $privilege]) {
    $pdo->exec($remove);
    $beforeDenied = $snapshot();
    migrationCli(['--check'], 2, "Pending: $operation; required privilege: $privilege.");
    $denied = migrationCli([], 1, "Privilege/authentication failure during $operation; required privilege: $privilege;");
    check(str_contains($denied, 'SQLSTATE=42000; driver=1142'), 'CLI omitted precise privilege denial');
    check($snapshot() === $beforeDenied, 'Denied CLI migration changed schema or data');
    Migrations::run($pdo, $schemaFile);
    migrationCli([], 0, 'no changes required');
}
$pdo->exec('ALTER TABLE recovery_requests DROP COLUMN restore_public_key');
migrationCli(['--check'], 2, 'add column recovery_requests.restore_public_key; required privilege: ALTER');
migrationCli([], 1, 'Privilege/authentication failure during add column recovery_requests.restore_public_key');
$credentials = dirname($socket) . '/deployment.json';
file_put_contents($credentials, json_encode(['user' => 'root', 'password' => '']));
chmod($credentials, 0644);
migrationCli(['--credentials', $credentials], 1, 'regular private file');
chmod($credentials, 0600);
migrationCli(['--check', '--credentials', $credentials], 2, 'inspection only');
migrationCli(['--credentials', $credentials], 0, 'Schema migration completed');
migrationCli(['--check'], 0, 'no changes required');
unlink($credentials);
// A real partial DDL failure must leave completed work discoverable and retryable.
$pdo->exec('DROP TABLE deletion_requests');
$pdo->exec('ALTER TABLE recovery_requests DROP COLUMN restore_public_key');
$pdo->exec("CREATE USER 'bm_partial'@'localhost'");
$pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON bm_fixture.* TO 'bm_partial'@'localhost'");
$partial = new PDO('mysql:unix_socket=' . $socket . ';dbname=bm_fixture', 'bm_partial', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
migrationFailure($partial, 'add column recovery_requests.restore_public_key');
$remaining = Migrations::run($runtime, $schemaFile, true);
check(count($remaining) === 1 && $remaining[0]['id'] === 'add column recovery_requests.restore_public_key', 'Partial DDL was not discovered on retry');
Migrations::run($pdo, $schemaFile);
migrationCli(['--check'], 0, 'no changes required');

// An equivalent legacy index does not require INDEX just to adopt a preferred name.
$pdo->exec('ALTER TABLE clients RENAME INDEX uq_clients_source_id TO legacy_source_unique');
check(Migrations::run($runtime, $schemaFile) === [], 'Equivalent legacy index caused redundant DDL');
$pdo->exec('ALTER TABLE clients RENAME INDEX legacy_source_unique TO uq_clients_source_id');
// Generated-column DDL uses string literals even under ANSI_QUOTES.
$pdo->exec('DROP INDEX uq_provider_requests_pending_source_id ON provider_requests');
$pdo->exec('ALTER TABLE provider_requests DROP COLUMN pending_source_id');
$mode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$pdo->exec("SET SESSION sql_mode='ANSI_QUOTES'");
Migrations::run($pdo, $schemaFile);
$pdo->exec('SET SESSION sql_mode=' . $pdo->quote($mode));
check(Migrations::run($runtime, $schemaFile) === [], 'ANSI_QUOTES migration was not idempotent');

// Exercise the documented temporary deployment-account workflow, without root migrations.
// Seed the ncdev identity before the real upgrade; reuse it in later recovery tests.
$pdo->exec("INSERT INTO clients (client_id, source_id, source_url, approved_at, approved_by) VALUES ('BM-000007', 'ncdev-legacy', 'https://ncdev.example.test', UTC_TIMESTAMP(), 'fixture')");
$pdo->exec("INSERT INTO storage_allocations (client_id, destination_type, storage_host, storage_port, storage_user, storage_path) VALUES ('BM-000007','ssh','storage.example.test',22,'backupstore','/var/lib/backupmanager-provider/BM-000007')");
$runtimeGrants = $pdo->query("SHOW GRANTS FOR 'bm_runtime'@'localhost'")->fetchAll(PDO::FETCH_COLUMN);
$clientRows = $pdo->query('SELECT * FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$storageRows = $pdo->query('SELECT * FROM storage_allocations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check(count(array_filter($clientRows, static fn(array $row): bool => $row['client_id'] === 'BM-000007')) === 1, 'BM-000007 preservation fixture missing');
check(count($storageRows) === 1 && $storageRows[0]['client_id'] === 'BM-000007'
    && $storageRows[0]['storage_path'] === '/var/lib/backupmanager-provider/BM-000007', 'BM-000007 storage fixture missing');
$assertPreserved = static function (string $stage) use ($pdo, $clientRows, $storageRows): void {
    check($pdo->query('SELECT * FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $clientRows, "$stage changed client records including BM-000007");
    check($pdo->query('SELECT * FROM storage_allocations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $storageRows, "$stage changed BM-000007 storage records");
};
$pdo->exec('DROP TABLE deletion_requests');
$pdo->exec('ALTER TABLE recovery_requests DROP COLUMN restore_public_key');
$pdo->exec('DROP INDEX uq_provider_requests_pending_source_id ON provider_requests');
$pdo->exec("CREATE USER 'bm_deployment'@'localhost' IDENTIFIED BY 'fixture-deployment-password'");
$pdo->exec("GRANT SELECT ON bm_fixture.* TO 'bm_deployment'@'localhost'");
file_put_contents($credentials, json_encode(['user' => 'bm_deployment', 'password' => 'fixture-deployment-password']));
chmod($credentials, 0600);
try {
    $pending = Migrations::run($runtime, $schemaFile, true);
    check(array_column($pending, 'privilege') === ['CREATE', 'ALTER', 'INDEX'], 'Unexpected temporary privilege requirements');
    migrationCli(['--credentials', $credentials], 1, 'Privilege/authentication failure during create table deletion_requests');
    // No UPDATE, INSERT, DELETE, DROP or global grants on the deployment account.
    $pdo->exec("GRANT CREATE, ALTER, INDEX ON bm_fixture.* TO 'bm_deployment'@'localhost'");
    migrationCli(['--check', '--credentials', $credentials], 2, 'inspection only');
    migrationCli(['--credentials', $credentials], 0, 'Schema migration completed');
} finally {
    $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'bm_deployment'@'localhost'");
    $revoked = $pdo->query("SHOW GRANTS FOR 'bm_deployment'@'localhost'")->fetchAll(PDO::FETCH_COLUMN);
    check(count($revoked) === 1 && str_starts_with($revoked[0], 'GRANT USAGE ON *.*'), 'Temporary privileges were not revoked');
    $pdo->exec("DROP USER 'bm_deployment'@'localhost'");
    unlink($credentials);
}
check($pdo->query("SHOW GRANTS FOR 'bm_runtime'@'localhost'")->fetchAll(PDO::FETCH_COLUMN) === $runtimeGrants, 'Runtime grants changed during upgrade');
$assertPreserved('Temporary-privilege migration');
migrationCli(['--check'], 0, 'no changes required');
migrationCli([], 0, 'no changes required');
$assertPreserved('CRUD-only check and migration after privilege revocation');
