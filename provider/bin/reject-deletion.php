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
$rejectedBy = trim((string)($argv[2] ?? ''));

if ($requestId === '' || $rejectedBy === '') {
    fail('Usage: php reject-deletion.php DELETION_REQUEST_ID REJECTED_BY');
}

try {
    $config = new Config(dirname(__DIR__) . '/config/config.php');
    $db = new Database($config);
    $pdo = $db->pdo();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT deletion_request_id, client_id, status
         FROM deletion_requests
         WHERE deletion_request_id = :request_id
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

    $reject = $pdo->prepare(
        'UPDATE deletion_requests
         SET status = "rejected",
             rejected_at = UTC_TIMESTAMP(),
             approved_by = :rejected_by
         WHERE deletion_request_id = :request_id'
    );

    $reject->execute([
        'rejected_by' => $rejectedBy,
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
            "deletion.rejected",
            "warning",
            :details
        )'
    );

    $event->execute([
        'actor_id' => $rejectedBy,
        'client_id' => $row['client_id'],
        'details' => json_encode([
            'deletion_request_id' => $requestId,
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->commit();

    echo "DELETION REJECTED\n";
    echo "Request ID : {$requestId}\n";
    echo "Client ID  : {$row['client_id']}\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fail($e->getMessage());
}
