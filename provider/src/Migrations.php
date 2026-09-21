<?php
declare(strict_types=1);
namespace BackupManager\Provider;
use PDO;
use RuntimeException;
final class Migrations {
    public static function run(PDO $pdo, string $schemaFile): void {
    if ((int)$pdo->query("SELECT GET_LOCK('backupmanager-migration', 30)")->fetchColumn() !== 1) {
        throw new RuntimeException('Migration busy');
    }
    $schema = (string)file_get_contents($schemaFile);
    foreach (explode(';', $schema) as $statement) {
        if (trim($statement) !== '') { $pdo->exec($statement); }
    }
    $columns = [
        'clients' => ['api_token_hash' => 'CHAR(64) DEFAULT NULL'],
        'provider_requests' => [
            'requester_email' => 'VARCHAR(255) DEFAULT NULL',
            'restore_public_key' => 'TEXT DEFAULT NULL',
            'approval_token_hash' => 'CHAR(64) DEFAULT NULL',
            'approval_token_expires_at' => 'DATETIME DEFAULT NULL',
            'approval_token_used_at' => 'DATETIME DEFAULT NULL',
            'pending_source_id' => 'VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN status = "pending" THEN source_id ELSE NULL END) STORED',
        ],
        'recovery_requests' => ['restore_public_key' => 'TEXT DEFAULT NULL'],
        'ssh_keys' => ['restore_public_key' => 'TEXT DEFAULT NULL'],
        'storage_allocations' => ['active_client_id' => 'VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN status = "active" THEN client_id ELSE NULL END) STORED'],
    ];
    foreach ($columns as $table => $definitions) {
        $existing = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($definitions as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
    }
    // Preserve existing API credentials while decoupling identity from onboarding requests.
    $pdo->exec('UPDATE clients c SET api_token_hash = (
        SELECT request_token_hash FROM provider_requests p
        WHERE p.source_id = c.source_id AND p.status = "approved" ORDER BY p.id DESC LIMIT 1
    ) WHERE c.api_token_hash IS NULL');
    $pdo->exec('UPDATE provider_requests SET approval_token_hash = NULL, approval_token_expires_at = NULL');
    foreach (['clients' => ['uq_clients_source_id', 'source_id'],
              'provider_requests' => ['uq_provider_requests_pending_source_id', 'pending_source_id'],
              'storage_allocations' => ['uq_storage_allocations_active_client', 'active_client_id']] as $table => [$index, $column]) {
        $indexes = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll();
        if (!in_array($index, array_column($indexes, 'Key_name'), true)) {
            $duplicate = $pdo->query('SELECT 1 FROM `' . $table . '` WHERE `' . $column . '` IS NOT NULL GROUP BY `' . $column . '` HAVING COUNT(*) > 1 LIMIT 1')->fetchColumn();
            if ($duplicate !== false) {
                throw new RuntimeException('Duplicate records require operator reconciliation; no records were deleted');
            }
            $pdo->exec('ALTER TABLE `' . $table . '` ADD UNIQUE KEY `' . $index . '` (`' . $column . '`)');
        }
    }
        $pdo->query("SELECT RELEASE_LOCK('backupmanager-migration')");
    }
}
