<?php
declare(strict_types=1);
namespace BackupManager\Provider;
use PDO;
use PDOException;
use Throwable;

final class Migrations {
    // Only messages constructed here are safe for operator output; never print driver text.
    public static function describe(Throwable $error, string $operation = 'connect/inspect database', string $privilege = 'SELECT'): string {
        if ($error instanceof PDOException) {
            $state = (string)($error->errorInfo[0] ?? $error->getCode());
            $state = preg_match('/^[A-Z0-9]{5}$/D', $state) ? $state : 'unknown';
            $number = (int)($error->errorInfo[1] ?? 0);
            $kind = match (true) {
                in_array($number, [1044, 1045, 1142, 1143, 1227, 1698], true) => 'Privilege/authentication failure',
                $number === 1062 => 'Duplicate legacy data; reconcile duplicate rows without deleting client identity',
                in_array($number, [1005, 1050, 1054, 1060, 1061, 1072, 1146, 1215, 1822, 3780], true) => 'Schema conflict; inspect the named schema object',
                default => 'SQL failure; consult database diagnostics using the SQLSTATE and driver code',
            };
            return "$kind during $operation; required privilege: $privilege; SQLSTATE=$state; driver=$number.";
        }
        return 'Configuration, schema-file or migration runtime failure; verify deployment files and configuration (exception details suppressed).';
    }

    private static function operation(string $id, string $privilege, string $sql): array {
        return compact('id', 'privilege', 'sql');
    }

    /** Compare SQL structure without changing the contents of string literals. */
    private static function expressionTokens(string $expression): array {
        preg_match_all(<<<'REGEX'
/'(?:''|\\.|[^'\\])*'|`(?:``|[^`])*`|"(?:""|\\.|[^"\\])*"|[A-Za-z_][A-Za-z0-9_]*|[^\s]/
REGEX, $expression, $matches);
        $tokens = [];
        foreach ($matches[0] as $i => $token) {
            if ($token === '(' || $token === ')') { continue; }
            // MySQL may include a charset introducer before a string literal.
            if (preg_match('/^_[a-zA-Z0-9]+$/D', $token) && str_starts_with($matches[0][$i + 1] ?? '', "'")) { continue; }
            if ($token[0] === "'") {
                $tokens[] = $token;
            } elseif ($token[0] === '`' || $token[0] === '"') {
                // MariaDB uses double-quoted identifiers in ANSI_QUOTES mode.
                $tokens[] = strtolower(substr($token, 1, -1));
            } else {
                $tokens[] = strtolower($token);
            }
        }
        return $tokens;
    }

    /** Inspect first. No DDL or DML is executed by this method. */
    public static function plan(PDO $pdo, string $schemaFile): array {
        $schema = @file_get_contents($schemaFile);
        if ($schema === false) { throw new MigrationFailure('Schema file could not be read.'); }
        $mariaDb = stripos((string)$pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') !== false;
        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $existingTables = array_column($tables, 1, 0);
        $plan = [];
        $missing = [];
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) === '') { continue; }
            if (!preg_match('/CREATE TABLE IF NOT EXISTS `?([a-z_]+)`?\s*\(/', $statement, $match)) {
                throw new MigrationFailure('Schema file contains an unsupported statement.');
            }
            $table = $match[1];
            if (!isset($existingTables[$table])) {
                $missing[$table] = true;
                preg_match_all('/REFERENCES\s+`?([a-z_]+)`?/', $statement, $parents);
                $privilege = 'CREATE';
                if (!$mariaDb && $parents[1]) { $privilege .= '; REFERENCES on ' . implode(', ', array_unique($parents[1])); }
                $plan[] = self::operation("create table $table", $privilege, $statement);
            } elseif ($existingTables[$table] !== 'BASE TABLE') {
                throw new MigrationFailure("Schema conflict: $table exists but is not a base table.");
            }
        }
        $columns = [
            'clients' => ['api_token_hash' => 'CHAR(64) DEFAULT NULL'],
            'provider_requests' => [
                'requester_email' => 'VARCHAR(255) DEFAULT NULL',
                'restore_public_key' => 'TEXT DEFAULT NULL',
                'approval_token_hash' => 'CHAR(64) DEFAULT NULL',
                'approval_token_expires_at' => 'DATETIME DEFAULT NULL',
                'approval_token_used_at' => 'DATETIME DEFAULT NULL',
                'pending_source_id' => "VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN source_id ELSE NULL END) STORED",
            ],
            'recovery_requests' => ['restore_public_key' => 'TEXT DEFAULT NULL'],
            'ssh_keys' => ['restore_public_key' => 'TEXT DEFAULT NULL'],
            'storage_allocations' => ['active_client_id' => "VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN client_id ELSE NULL END) STORED"],
        ];
        foreach ($columns as $table => $definitions) {
            if (isset($missing[$table])) { continue; }
            $existing = $pdo->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            $existing = array_column($existing, null, 'Field');
            foreach ($definitions as $name => $definition) {
                if (!isset($existing[$name])) {
                    $plan[] = self::operation("add column $table.$name", $mariaDb ? 'ALTER' : 'ALTER (CREATE, INSERT may also be required for MySQL table rebuild)', 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $definition);
                    continue;
                }
                $type = strtolower(explode(' ', $definition)[0]);
                $row = $existing[$name];
                if (strtolower($row['Type']) !== $type || $row['Null'] !== 'YES'
                    || ($row['Default'] !== null && strtoupper((string)$row['Default']) !== 'NULL')) {
                    throw new MigrationFailure("Schema conflict: $table.$name must be nullable $type with DEFAULT NULL; no automatic replacement attempted.");
                }
                if (str_contains($definition, 'GENERATED ALWAYS')) {
                    $query = $pdo->prepare('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                    $query->execute([$table, $name]);
                    preg_match('/AS \((.*)\) STORED/', $definition, $expression);
                    if (!str_contains($row['Extra'], 'STORED GENERATED') || self::expressionTokens((string)$query->fetchColumn()) !== self::expressionTokens($expression[1])) {
                        throw new MigrationFailure("Schema conflict: $table.$name has an incompatible generated expression or storage mode.");
                    }
                } elseif (str_contains($row['Extra'], 'GENERATED')) {
                    throw new MigrationFailure("Schema conflict: $table.$name must not be a generated column.");
                }
            }
        }
        foreach (['clients' => ['uq_clients_source_id', 'source_id', 'source_id', '1=1'],
                  'provider_requests' => ['uq_provider_requests_pending_source_id', 'pending_source_id', 'source_id', "status = 'pending'"],
                  'storage_allocations' => ['uq_storage_allocations_active_client', 'active_client_id', 'client_id', "status = 'active'"]] as $table => [$index, $column, $source, $where]) {
            if (isset($missing[$table])) { continue; }
            $indexes = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            $named = array_values(array_filter($indexes, static fn(array $row): bool => $row['Key_name'] === $index));
            if ($named !== []) {
                if (count($named) !== 1 || (int)$named[0]['Non_unique'] !== 0 || $named[0]['Column_name'] !== $column || $named[0]['Sub_part'] !== null) {
                    throw new MigrationFailure("Schema conflict: $table.$index must be a full-column UNIQUE index on $column.");
                }
                continue;
            }
            // Legacy installations may already enforce this constraint under another name.
            $groups = [];
            foreach ($indexes as $row) { $groups[$row['Key_name']][] = $row; }
            foreach ($groups as $rows) {
                if (count($rows) === 1 && (int)$rows[0]['Non_unique'] === 0
                    && $rows[0]['Column_name'] === $column && $rows[0]['Sub_part'] === null) {
                    continue 2;
                }
            }
            // Check underlying legacy columns even when the generated column is not installed yet.
            $duplicate = $pdo->query("SELECT 1 FROM `$table` WHERE $where AND `$source` IS NOT NULL GROUP BY `$source` HAVING COUNT(*) > 1 LIMIT 1")->fetchColumn();
            if ($duplicate !== false) {
                throw new MigrationFailure("Duplicate legacy data blocks create unique index $table.$index on $column (required privilege: INDEX); reconcile duplicate $source groups where $where. No records were deleted.");
            }
            $plan[] = self::operation("create unique index $table.$index on $column", 'INDEX', "CREATE UNIQUE INDEX `$index` ON `$table` (`$column`)");
        }
        // Retryable data changes: touch only legacy rows that actually need conversion.
        if (!isset($missing['clients']) && !isset($missing['provider_requests'])) {
            $clientColumns = $pdo->query('SHOW COLUMNS FROM clients')->fetchAll(PDO::FETCH_COLUMN);
            $hasToken = in_array('api_token_hash', $clientColumns, true);
            $where = $hasToken ? 'c.api_token_hash IS NULL AND' : '';
            if ($pdo->query("SELECT 1 FROM clients c WHERE $where EXISTS (SELECT 1 FROM provider_requests p WHERE p.source_id=c.source_id AND p.status='approved') LIMIT 1")->fetchColumn() !== false) {
                $plan[] = self::operation('backfill clients.api_token_hash from approved provider_requests', 'SELECT, UPDATE',
                    "UPDATE clients c SET api_token_hash = (SELECT request_token_hash FROM provider_requests p WHERE p.source_id=c.source_id AND p.status='approved' ORDER BY p.id DESC LIMIT 1) WHERE c.api_token_hash IS NULL AND EXISTS (SELECT 1 FROM provider_requests p WHERE p.source_id=c.source_id AND p.status='approved')");
            }
        }
        if (!isset($missing['provider_requests'])) {
            $fields = $pdo->query('SHOW COLUMNS FROM provider_requests')->fetchAll(PDO::FETCH_COLUMN);
            $conditions = [];
            foreach (['approval_token_hash', 'approval_token_expires_at'] as $field) {
                if (in_array($field, $fields, true)) { $conditions[] = "$field IS NOT NULL"; }
            }
            if ($conditions && $pdo->query('SELECT 1 FROM provider_requests WHERE ' . implode(' OR ', $conditions) . ' LIMIT 1')->fetchColumn() !== false) {
                $plan[] = self::operation('retire legacy provider_requests email approval tokens', 'UPDATE',
                    'UPDATE provider_requests SET approval_token_hash=NULL, approval_token_expires_at=NULL WHERE approval_token_hash IS NOT NULL OR approval_token_expires_at IS NOT NULL');
            }
        }
        return $plan;
    }

    public static function run(PDO $pdo, string $schemaFile, bool $check = false, ?callable $report = null): array {
        $locked = false;
        $operation = 'acquire migration lock';
        $privilege = 'none (GET_LOCK)';
        try {
            if ((int)$pdo->query("SELECT GET_LOCK('backupmanager-migration', 30)")->fetchColumn() !== 1) {
                throw new MigrationFailure('Migration busy: another migration holds the lock; retry later.');
            }
            $locked = true;
            $operation = 'inspect schema and legacy data';
            $privilege = 'SELECT';
            $plan = self::plan($pdo, $schemaFile);
            // IF NOT EXISTS still requires CREATE. A current database must execute
            // no DDL or DML; finally releases the lock on this no-op path too.
            if ($plan === []) { return []; }
            foreach ($plan as $change) {
                if ($report !== null) { $report("Pending: {$change['id']}; required privilege: {$change['privilege']}."); }
            }
            if (!$check) {
                foreach ($plan as $change) {
                    $operation = $change['id'];
                    $privilege = $change['privilege'];
                    $pdo->exec($change['sql']);
                }
            }
            return $plan;
        } catch (MigrationFailure $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new MigrationFailure(self::describe($error, $operation, $privilege), 0, $error);
        } finally {
            if ($locked) {
                // Preserve the primary failure if the connection was lost.
                try { $pdo->query("SELECT RELEASE_LOCK('backupmanager-migration')"); } catch (Throwable) {}
            }
        }
    }
}
