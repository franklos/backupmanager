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

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($code);
}

if (PHP_SAPI !== 'cli') {
    fail('This command may only be run from CLI.');
}

$requestId = trim((string)($argv[1] ?? ''));
$rejectedBy = trim((string)($argv[2] ?? ''));

if ($requestId === '') {
    fail('Usage: php reject-request.php REQUEST_ID REJECTED_BY');
}

if ($rejectedBy === '') {
    fail('rejected_by is required');
}

try {
    $config = new Config(dirname(__DIR__) . '/config/config.php');
    $database = new Database($config);
    $pdo = $database->pdo();

    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT *
         FROM provider_requests
         WHERE request_id = :request_id
         FOR UPDATE'
    );

    $statement->execute([
        'request_id' => $requestId,
    ]);

    $request = $statement->fetch();

    if (!is_array($request)) {
        throw new RuntimeException('Request not found');
    }

    if ($request['status'] !== 'pending') {
        throw new RuntimeException(
            'Request is not pending; current status: ' . $request['status']
        );
    }

    $reject = $pdo->prepare(
        'UPDATE provider_requests
         SET status = "rejected",
             rejected_at = UTC_TIMESTAMP()
         WHERE request_id = :request_id'
    );

    $reject->execute([
        'request_id' => $requestId,
    ]);

    $event = $pdo->prepare(
        'INSERT INTO provider_events (
            actor_type,
            actor_id,
            request_id,
            event_type,
            severity,
            details
        ) VALUES (
            "administrator",
            :actor_id,
            :request_id,
            "request.rejected",
            "warning",
            :details
        )'
    );

    $event->execute([
        'actor_id' => $rejectedBy,
        'request_id' => $requestId,
        'details' => json_encode([
            'request_id' => $requestId,
            'source_id' => $request['source_id'],
            'source_url' => $request['source_url'],
            'ssh_fingerprint' => $request['ssh_fingerprint'],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $delete = $pdo->prepare(
        'DELETE FROM provider_requests WHERE request_id = :request_id'
    );
    $delete->execute(['request_id' => $requestId]);

    $pdo->commit();

    echo "REQUEST REJECTED\n";
    echo "Request ID : {$requestId}\n";
    echo "Source     : {$request['source_id']}\n";
    echo "Rejected by: {$rejectedBy}\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fail($e->getMessage());
}
