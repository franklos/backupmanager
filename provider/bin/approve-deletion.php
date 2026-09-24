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
    $config = new Config();
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
          AND s.status IN ("active", "suspended")
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

    if (!in_array($row['status'], ['pending', 'approved', 'failed'], true)) {
        throw new RuntimeException(
            'Deletion request is not pending; current status: ' . $row['status']
        );
    }

    $clientId = (string)$row['client_id'];
    // Commit a suspended identity before nontransactional key/storage changes.
    $suspend = $pdo->prepare('UPDATE clients SET status = "suspended" WHERE client_id = :client');
    $suspend->execute(['client' => $clientId]);
    $intent = $pdo->prepare('UPDATE deletion_requests SET status = "approved", approved_at = UTC_TIMESTAMP(), approved_by = :actor WHERE deletion_request_id = :request');
    $intent->execute(['actor' => $approvedBy, 'request' => $requestId]);
    $pdo->commit();

    $removeAuthorizedKey = sprintf(
        'sudo /usr/local/sbin/backupmanager-remove-authorized-key %s',
        escapeshellarg($clientId)
    );

    exec($removeAuthorizedKey, $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('Unable to remove authorized SSH key');
    }

    if ((int)$row['delete_storage'] === 1) {
        $command = 'sudo -n /usr/local/sbin/backupmanager-remove-storage ' . escapeshellarg($clientId);
        exec($command, $deleteOutput, $deleteCode);
        if ($deleteCode !== 0) {
            throw new RuntimeException('Storage deletion failed; access remains suspended');
        }
    }
    $pdo->beginTransaction();

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
           AND status IN ("active", "suspended")'
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

    if (isset($clientId, $pdo)) {
        try {
            $failed = $pdo->prepare('UPDATE deletion_requests SET status = "failed", error_message = "Operation failed; retry after reviewing storage and helper availability" WHERE deletion_request_id = :request AND status = "approved"');
            $failed->execute(['request' => $requestId]);
        } catch (Throwable) { /* Suspended identity and durable intent remain for operator recovery. */ }
    }
    fail('Deletion did not complete; access remains suspended. Review and retry the request.');
}
