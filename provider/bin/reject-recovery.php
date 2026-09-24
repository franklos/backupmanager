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

if ($requestId === '' || $rejectedBy === '') {
    fail('Usage: php reject-recovery.php RECOVERY_REQUEST_ID REJECTED_BY');
}

if (!preg_match('/^REC-[0-9]{8}-[A-F0-9]{6}$/D', $requestId)) { fail('Invalid request type or ID'); }

try {
    $config = new Config();
    $database = new Database($config);
    $pdo = $database->pdo();

    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT *
         FROM recovery_requests
         WHERE recovery_request_id = :request_id
         FOR UPDATE'
    );

    $statement->execute([
        'request_id' => $requestId,
    ]);

    $request = $statement->fetch();

    if (!is_array($request)) {
        throw new RuntimeException('Recovery request not found');
    }

    if ($request['status'] !== 'pending') {
        throw new RuntimeException(
            'Recovery request is not pending; current status: ' . $request['status']
        );
    }

    if (empty($request['expires_at']) || strtotime($request['expires_at'] . ' UTC') <= time()) {
        throw new RuntimeException('Request has expired');
    }

    $reject = $pdo->prepare(
        'UPDATE recovery_requests
         SET status = "rejected",
             rejected_at = UTC_TIMESTAMP(),
             approved_by = :rejected_by
         WHERE recovery_request_id = :request_id'
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
            "recovery.rejected",
            "security",
            :details
        )'
    );

    $event->execute([
        'actor_id' => $rejectedBy,
        'client_id' => $request['client_id'],
        'details' => json_encode([
            'recovery_request_id' => $requestId,
            'source_id' => $request['source_id'],
            'ssh_fingerprint' => $request['ssh_fingerprint'],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->commit();

    echo "RECOVERY REJECTED\n";
    echo "Request ID : {$requestId}\n";
    echo "Client ID  : {$request['client_id']}\n";
    echo "Rejected by: {$rejectedBy}\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fail($e->getMessage());
}
