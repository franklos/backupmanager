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
$approvedBy = trim((string)($argv[2] ?? ''));

if ($requestId === '') {
    fail('Usage: php approve.php REQUEST_ID APPROVED_BY');
}

if ($approvedBy === '') {
    fail('approved_by is required');
}

try {
    $config = new Config(dirname(__DIR__) . '/config/config.php');
    $database = new Database($config);
    $pdo = $database->pdo();

    $pdo->beginTransaction();

    /*
     * Lock de aanvraag zodat twee beheerders niet tegelijk
     * dezelfde request kunnen goedkeuren.
     */
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

    if (
        !empty($request['expires_at'])
        && strtotime((string)$request['expires_at']) < time()
    ) {
        $expire = $pdo->prepare(
            'UPDATE provider_requests
             SET status = "expired"
             WHERE request_id = :request_id'
        );

        $expire->execute([
            'request_id' => $requestId,
        ]);

        $pdo->commit();

        fail('Request has expired');
    }

    /*
     * Bepaal volgend permanent client-ID.
     * De interne auto_increment-ID blijft technisch;
     * BM-xxxxxx is de publieke permanente identiteit.
     */
    $next = $pdo->query(
        'SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM clients'
    )->fetch();

    $sequence = (int)($next['next_id'] ?? 1);
    $clientId = sprintf('BM-%06d', $sequence);

    /*
     * Bij een race kan de UNIQUE constraint dit alsnog blokkeren.
     * De gehele transactie wordt dan teruggedraaid.
     */
    $clientInsert = $pdo->prepare(
        'INSERT INTO clients (
            client_id,
            source_id,
            source_url,
            status,
            approved_at,
            approved_by,
            client_version,
            nextcloud_version
        ) VALUES (
            :client_id,
            :source_id,
            :source_url,
            "active",
            UTC_TIMESTAMP(),
            :approved_by,
            :client_version,
            :nextcloud_version
        )'
    );

    $clientInsert->execute([
        'client_id' => $clientId,
        'source_id' => $request['source_id'],
        'source_url' => $request['source_url'],
        'approved_by' => $approvedBy,
        'client_version' => $request['client_version'],
        'nextcloud_version' => $request['nextcloud_version'],
    ]);

    $keyInsert = $pdo->prepare(
        'INSERT INTO ssh_keys (
            client_id,
            public_key,
            fingerprint,
            status
        ) VALUES (
            :client_id,
            :public_key,
            :fingerprint,
            "active"
        )'
    );

    $keyInsert->execute([
        'client_id' => $clientId,
        'public_key' => $request['public_key'],
        'fingerprint' => $request['ssh_fingerprint'],
    ]);

    $storageRoot = rtrim(
        (string)$config->get(
            'storage',
            'root',
            '/var/lib/backupmanager/provider-storage'
        ),
        '/'
    );

    $storagePath = $storageRoot . '/' . $clientId;

    $storageInsert = $pdo->prepare(
        'INSERT INTO storage_allocations (
            client_id,
            destination_type,
            storage_host,
            storage_port,
            storage_user,
            storage_path,
            status
        ) VALUES (
            :client_id,
            "ssh",
            :storage_host,
            :storage_port,
            :storage_user,
            :storage_path,
            "active"
        )'
    );

    $storageInsert->execute([
        'client_id' => $clientId,
        'storage_host' => $config->get('storage', 'host', 'localhost'),
        'storage_port' => (int)$config->get('storage', 'port', 22),
        'storage_user' => $config->get('storage', 'user', 'backupmanager'),
        'storage_path' => $storagePath,
    ]);

    $approve = $pdo->prepare(
        'UPDATE provider_requests
         SET status = "approved",
             approved_at = UTC_TIMESTAMP()
         WHERE request_id = :request_id'
    );

    $approve->execute([
        'request_id' => $requestId,
    ]);

    $event = $pdo->prepare(
        'INSERT INTO provider_events (
            actor_type,
            actor_id,
            client_id,
            request_id,
            event_type,
            severity,
            details
        ) VALUES (
            "administrator",
            :actor_id,
            :client_id,
            :request_id,
            "request.approved",
            "info",
            :details
        )'
    );

    $event->execute([
        'actor_id' => $approvedBy,
        'client_id' => $clientId,
        'request_id' => $requestId,
        'details' => json_encode([
            'storage_path' => $storagePath,
            'ssh_fingerprint' => $request['ssh_fingerprint'],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    /*
     * Maak de opslagdirectory vóór commit.
     * Als dit mislukt wordt ook de database teruggedraaid.
     */
    if (!is_dir($storagePath)) {
        if (!mkdir($storagePath, 0750, true) && !is_dir($storagePath)) {
            throw new RuntimeException(
                'Unable to create storage directory: ' . $storagePath
            );
        }
    }

    foreach (['data', 'database'] as $directory) {
        $path = $storagePath . '/' . $directory;

        if (!is_dir($path)) {
            if (!mkdir($path, 0750, true) && !is_dir($path)) {
                throw new RuntimeException(
                    'Unable to create directory: ' . $path
                );
            }
        }
    }

    $pdo->commit();

    echo "APPROVED\n";
    echo "Request ID : {$requestId}\n";
    echo "Client ID  : {$clientId}\n";
    echo "Source     : {$request['source_id']}\n";
    echo "Storage    : {$storagePath}\n";
    echo "Fingerprint: {$request['ssh_fingerprint']}\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fail($e->getMessage());
}
