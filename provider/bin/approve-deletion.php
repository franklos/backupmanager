<?php

declare(strict_types=1);

use BackupManager\Provider\Config;
use BackupManager\Provider\Database;

spl_autoload_register(function (string $class): void {
    $prefix = 'BackupManager\\Provider\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

$requestId = trim((string)($argv[1] ?? ''));
$approvedBy = trim((string)($argv[2] ?? ''));

if ($requestId === '' || $approvedBy === '') {
    fail('Usage: php approve-deletion.php DELETION_REQUEST_ID APPROVED_BY');
}

try {
    $config = new Config(dirname(__DIR__) . '/config/config.php');
    $db = new Database($config);
    $pdo = $db->pdo();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT
            dr.deletion_request_id,
            dr.client_id,
            dr.status,
            dr.delete_storage,
            c.source_id,
            c.status AS client_status,
            s.storage_path
         FROM deletion_requests dr
         JOIN clients c
           ON c.client_id = dr.client_id
         LEFT JOIN storage_allocations s
           ON s.client_id = dr.client_id
          AND s.status = "active"
          AND s.destination_type = "ssh"
         WHERE dr.deletion_request_id = :request_id
         FOR UPDATE'
    );

    $stmt->execute([
        'request_id' => $requestId,
    ]);

    $row = $stmt->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('Deletion request not found');
    }

    if ($row['status'] !== 'pending') {
        throw new RuntimeException(
            'Deletion request is not pending; current status: ' . $row['status']
        );
    }

    $clientId = (string)$row['client_id'];
    $storageRoot = realpath(
        (string)$config->get('storage', 'root', '/var/lib/backupmanager-provider')
    );

    $storagePath = null;

    if (!empty($row['storage_path'])) {
        $storagePath = realpath((string)$row['storage_path']);
    }

    if ((int)$row['delete_storage'] === 1) {
        if ($storageRoot === false || $storagePath === false || $storagePath === null) {
            throw new RuntimeException('Storage path could not be resolved');
        }

        if ($storagePath !== $storageRoot . '/' . $clientId) {
            throw new RuntimeException('Unsafe storage path');
        }

        $realRootPrefix = rtrim($storageRoot, '/') . '/';

        if (!str_starts_with($storagePath . '/', $realRootPrefix)) {
            throw new RuntimeException('Storage path escapes provider root');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $storagePath,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                if (!rmdir($path)) {
                    throw new RuntimeException('Unable to remove directory: ' . $path);
                }
            } else {
                if (!unlink($path)) {
                    throw new RuntimeException('Unable to remove file: ' . $path);
                }
            }
        }

        if (!rmdir($storagePath)) {
            throw new RuntimeException('Unable to remove client storage directory');
        }
    }

    $tmp = tempnam('/tmp', 'bm-del-auth-');

    if ($tmp === false) {
        throw new RuntimeException('Unable to create temporary authorized_keys file');
    }

    $authorizedKeys = '/var/lib/backupmanager-provider/.ssh/authorized_keys';

    $command = sprintf(
        'sudo awk %s %s > %s',
        escapeshellarg('index($0, "bm-client=' . $clientId . '") == 0 { print }'),
        escapeshellarg($authorizedKeys),
        escapeshellarg($tmp)
    );

    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        @unlink($tmp);
        throw new RuntimeException('Unable to prepare authorized_keys update');
    }

    $install = sprintf(
        'sudo /usr/local/sbin/backupmanager-install-authorized-keys %s',
        escapeshellarg($tmp)
    );

    /*
     * De bestaande helper verwacht een bm-client marker en is bedoeld
     * voor toevoegen/vervangen, niet voor verwijderen.
     * Daarom installeren we het gefilterde bestand hier direct.
     */
    $install = sprintf(
        'sudo install -o backupstore -g backupstore -m 600 %s %s && sudo rm -f %s',
        escapeshellarg($tmp),
        escapeshellarg($authorizedKeys),
        escapeshellarg($tmp)
    );

    exec($install, $output, $exitCode);

    if ($exitCode !== 0) {
        @unlink($tmp);
        throw new RuntimeException('Unable to update authorized_keys');
    }

    $revokeKey = $pdo->prepare(
        'UPDATE ssh_keys
         SET status = "revoked",
             revoked_at = UTC_TIMESTAMP()
         WHERE client_id = :client_id
           AND status = "active"'
    );

    $revokeKey->execute([
        'client_id' => $clientId,
    ]);

    $removeStorage = $pdo->prepare(
        'UPDATE storage_allocations
         SET status = "removed"
         WHERE client_id = :client_id
           AND status = "active"'
    );

    $removeStorage->execute([
        'client_id' => $clientId,
    ]);

    $terminateClient = $pdo->prepare(
        'UPDATE clients
         SET status = "terminated"
         WHERE client_id = :client_id'
    );

    $terminateClient->execute([
        'client_id' => $clientId,
    ]);

    $complete = $pdo->prepare(
        'UPDATE deletion_requests
         SET status = "completed",
             approved_at = UTC_TIMESTAMP(),
             completed_at = UTC_TIMESTAMP(),
             approved_by = :approved_by
         WHERE deletion_request_id = :request_id'
    );

    $complete->execute([
        'approved_by' => $approvedBy,
        'request_id' => $requestId,
    ]);

    $event = $pdo->prepare(
        'INSERT INTO provider_events (
            actor_type,
            actor_id,
            client_id,
            event_type,
            severity,
            details
        ) VALUES (
            "administrator",
            :actor_id,
            :client_id,
            "deletion.completed",
            "security",
            :details
        )'
    );

    $event->execute([
        'actor_id' => $approvedBy,
        'client_id' => $clientId,
        'details' => json_encode([
            'deletion_request_id' => $requestId,
            'storage_deleted' => (bool)$row['delete_storage'],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->commit();

    echo "DELETION COMPLETED\n";
    echo "Request ID : {$requestId}\n";
    echo "Client ID  : {$clientId}\n";
    echo "Storage    : " . ((int)$row['delete_storage'] === 1 ? 'deleted' : 'preserved') . "\n";
    echo "Client     : terminated\n";
    echo "SSH key    : revoked\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fail($e->getMessage());
}
