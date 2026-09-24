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

if ($requestId === '' || $approvedBy === '') {
    fail('Usage: php approve-recovery.php RECOVERY_REQUEST_ID APPROVED_BY');
}

if (!preg_match('/^REC-[0-9]{8}-[A-F0-9]{6}$/D', $requestId)) { fail('Invalid request type or ID'); }

try {
    $config = new Config();
    $database = new Database($config);
    $pdo = $database->pdo();

    if ((int)$pdo->query("SELECT GET_LOCK('backupmanager-approval', 30)")->fetchColumn() !== 1) {
        throw new RuntimeException('Approval busy');
    }
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

    if (
        empty($request['expires_at'])
        || strtotime((string)$request['expires_at'] . ' UTC') <= time()
    ) {
        $expire = $pdo->prepare(
            'UPDATE recovery_requests
             SET status = "expired"
             WHERE recovery_request_id = :request_id'
        );

        $expire->execute([
            'request_id' => $requestId,
        ]);

        $pdo->commit();
        fail('Recovery request has expired');
    }

    \BackupManager\Provider\StorageEndpoint::configured($config);

    $clientId = (string)$request['client_id'];

    $client = $pdo->prepare(
        'SELECT client_id, source_id, status
         FROM clients
         WHERE client_id = :client_id
         FOR UPDATE'
    );

    $client->execute([
        'client_id' => $clientId,
    ]);

    $clientData = $client->fetch();

    if (!is_array($clientData) || $clientData['status'] !== 'active') {
        throw new RuntimeException('Active client not found');
    }

    // A reviewed source change is allowed, but cannot take another client's identity.
    $sourceOwner = $pdo->prepare('SELECT client_id FROM clients WHERE source_id = ? FOR UPDATE');
    $sourceOwner->execute([$request['source_id']]);
    $owner = $sourceOwner->fetchColumn();
    if ($owner !== false && $owner !== $clientId) { throw new RuntimeException('Recovery source belongs to another client'); }

    $storage = $pdo->prepare(
        'SELECT storage_path, storage_host, storage_port
         FROM storage_allocations
         WHERE client_id = :client_id
           AND status = "active"
           AND destination_type = "ssh"
         LIMIT 1'
    );

    $storage->execute([
        'client_id' => $clientId,
    ]);

    $storageData = $storage->fetch();

    if (!is_array($storageData)) {
        throw new RuntimeException('Active SSH storage allocation not found');
    }

    \BackupManager\Provider\StorageEndpoint::validate($storageData['storage_host'], $storageData['storage_port']);
    $storagePath = (string)$storageData['storage_path'];
    if ($storagePath !== '/var/lib/backupmanager-provider/' . $clientId) {
        throw new RuntimeException('Existing storage allocation requires operator review; no keys or storage were changed');
    }

    /*
     * Oude actieve keys markeren als replaced.
     */
    $replace = $pdo->prepare(
        'UPDATE ssh_keys
         SET status = "replaced",
             revoked_at = UTC_TIMESTAMP()
         WHERE client_id = :client_id
           AND status = "active"'
    );

    $replace->execute([
        'client_id' => $clientId,
    ]);

    /*
     * Nieuwe key activeren.
     */
    $existingKey = $pdo->prepare('SELECT client_id FROM ssh_keys WHERE fingerprint = :fingerprint FOR UPDATE');
    $existingKey->execute(['fingerprint' => $request['ssh_fingerprint']]);
    $keyOwner = $existingKey->fetchColumn();
    if ($keyOwner !== false && $keyOwner !== $clientId) {
        throw new RuntimeException('Recovery key already belongs to another client');
    }
    $keyInsert = $pdo->prepare(
        'INSERT INTO ssh_keys (client_id, public_key, fingerprint, status)
         VALUES (:client_id, :public_key, :fingerprint, "active")
         ON DUPLICATE KEY UPDATE public_key = VALUES(public_key), status = "active", revoked_at = NULL'
    );
    $keyInsert->execute([
        'client_id' => $clientId,
        'public_key' => $request['public_key'],
        'fingerprint' => $request['ssh_fingerprint'],
    ]);

    /*
     * De permanente client blijft gelijk, maar source_id mag veranderen
     * na reinstall/recovery.
     */
    $clientUpdate = $pdo->prepare(
        'UPDATE clients
         SET source_id = :source_id
         WHERE client_id = :client_id'
    );

    $clientUpdate->execute([
        'source_id' => $request['source_id'],
        'client_id' => $clientId,
    ]);

    $approve = $pdo->prepare(
        'UPDATE recovery_requests
         SET status = "approved",
             approved_at = UTC_TIMESTAMP(),
             approved_by = :approved_by
         WHERE recovery_request_id = :request_id'
    );

    $approve->execute([
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
            "recovery.approved",
            "security",
            :details
        )'
    );

    $event->execute([
        'actor_id' => $approvedBy,
        'client_id' => $clientId,
        'details' => json_encode([
            'recovery_request_id' => $requestId,
            'old_source_id' => $clientData['source_id'],
            'new_source_id' => $request['source_id'],
            'ssh_fingerprint' => $request['ssh_fingerprint'],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $rotation = bin2hex(random_bytes(32));
    \BackupManager\Provider\Provisioning::install($clientId, (string)$request['public_key'], (string)$request['restore_public_key'], true, $rotation);
    $credentials = $pdo->prepare('UPDATE clients SET api_token_hash = :token WHERE client_id = :client');
    $credentials->execute(['token' => $request['request_token_hash'], 'client' => $clientId]);
    $readKey = $pdo->prepare('UPDATE ssh_keys SET restore_public_key = :key WHERE client_id = :client AND status = "active"');
    $readKey->execute(['key' => $request['restore_public_key'], 'client' => $clientId]);

    $pdo->commit();
    $committed = true;
    try { \BackupManager\Provider\Provisioning::finish($clientId, $rotation, 'commit'); }
    catch (Throwable) { error_log('Backup Manager credential transaction committed in DB but journal finalization needs operator reconciliation: ' . $clientId); }

    echo "RECOVERY APPROVED\n";
    echo "Request ID : {$requestId}\n";
    echo "Client ID  : {$clientId}\n";
    echo "Source old : {$clientData['source_id']}\n";
    echo "Source new : {$request['source_id']}\n";
    echo "Storage    : {$storagePath}\n";
    echo "Fingerprint: {$request['ssh_fingerprint']}\n";

} catch (Throwable $e) {
    if (isset($rotation) && empty($committed)) {
        try { \BackupManager\Provider\Provisioning::finish($clientId, $rotation, 'rollback'); }
        catch (Throwable) { error_log('Backup Manager credential rollback requires operator reconciliation: ' . $clientId); }
    }
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fail($e->getMessage());
}
