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

if (!preg_match('/^REQ-[0-9]{8}-[A-F0-9]{6}$/D', $requestId)) { fail('Invalid request type or ID'); }

try {
    $config = new Config();
    $database = new Database($config);
    $pdo = $database->pdo();

    if ((int)$pdo->query("SELECT GET_LOCK('backupmanager-approval', 30)")->fetchColumn() !== 1) {
        throw new RuntimeException('Approval busy');
    }
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
        empty($request['expires_at'])
        || strtotime((string)$request['expires_at'] . ' UTC') <= time()
    ) {
        $expire = $pdo->prepare(
            'UPDATE provider_requests
             SET status = "expired"
             WHERE request_id = :request_id'
        );

        $expire->execute([
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
                "system",
                "provider",
                :request_id,
                "request.expired",
                "warning",
                :details
            )'
        );

        $event->execute([
            'request_id' => $requestId,
            'details' => json_encode([
                'request_id' => $requestId,
                'source_id' => $request['source_id'],
                'source_url' => $request['source_url'],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $pdo->commit();

        fail('Request has expired');
    }

    \BackupManager\Provider\StorageEndpoint::configured($config);

    /*
     * Hergebruik een bestaande client voor dezelfde bron.
     * Alleen een werkelijk nieuwe bron krijgt een nieuw BM-client-ID.
     */
    $existingClient = $pdo->prepare(
        'SELECT client_id
         FROM clients
         WHERE source_id = :source_id
         LIMIT 1
         FOR UPDATE'
    );

    $existingClient->execute([
        'source_id' => $request['source_id'],
    ]);

    $client = $existingClient->fetch();

    if (is_array($client)) {
        $clientId = (string)$client['client_id'];

        $clientUpdate = $pdo->prepare(
            'UPDATE clients
             SET source_url = :source_url,
                 status = "active",
                 approved_at = UTC_TIMESTAMP(),
                 approved_by = :approved_by,
                 contact_email = :contact_email,
                 client_version = :client_version,
                 nextcloud_version = :nextcloud_version
             WHERE client_id = :client_id'
        );

        $clientUpdate->execute([
            'source_url' => $request['source_url'],
            'approved_by' => $approvedBy,
            'contact_email' => $request['requester_email'] ?: null,
            'client_version' => $request['client_version'],
            'nextcloud_version' => $request['nextcloud_version'],
            'client_id' => $clientId,
        ]);
    } else {
        $next = $pdo->query(
            'SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM clients'
        )->fetch();

        $sequence = (int)($next['next_id'] ?? 1);
        $clientId = sprintf('BM-%06d', $sequence);

        $clientInsert = $pdo->prepare(
            'INSERT INTO clients (
                client_id,
                source_id,
                source_url,
                status,
                approved_at,
                approved_by,
                contact_email,
                client_version,
                nextcloud_version
            ) VALUES (
                :client_id,
                :source_id,
                :source_url,
                "active",
                UTC_TIMESTAMP(),
                :approved_by,
                :contact_email,
                :client_version,
                :nextcloud_version
            )'
        );

        $clientInsert->execute([
            'client_id' => $clientId,
            'source_id' => $request['source_id'],
            'source_url' => $request['source_url'],
            'approved_by' => $approvedBy,
            'contact_email' => $request['requester_email'] ?: null,
            'client_version' => $request['client_version'],
            'nextcloud_version' => $request['nextcloud_version'],
        ]);
    }

    /*
     * Een hernieuwde koppeling vervangt de oude actieve SSH-sleutel.
     * Historie blijft in de database zichtbaar als "replaced".
     */
    $replaceKeys = $pdo->prepare(
        'UPDATE ssh_keys
         SET status = "replaced"
         WHERE client_id = :client_id
           AND status = "active"'
    );

    $replaceKeys->execute([
        'client_id' => $clientId,
    ]);

    $fingerprintLookup = $pdo->prepare(
        'SELECT id, client_id
         FROM ssh_keys
         WHERE fingerprint = :fingerprint
         LIMIT 1
         FOR UPDATE'
    );

    $fingerprintLookup->execute([
        'fingerprint' => $request['ssh_fingerprint'],
    ]);

    $existingKey = $fingerprintLookup->fetch();

    if (is_array($existingKey)) {
        if ((string)$existingKey['client_id'] !== $clientId) {
            throw new RuntimeException(
                'SSH fingerprint already belongs to another client'
            );
        }

        $keyUpdate = $pdo->prepare(
            'UPDATE ssh_keys
             SET public_key = :public_key,
                 status = "active"
             WHERE id = :id'
        );

        $keyUpdate->execute([
            'public_key' => $request['public_key'],
            'id' => $existingKey['id'],
        ]);
    } else {
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
    }

    $storageRoot = rtrim(
        (string)$config->get(
            'storage',
            'root',
            '/var/lib/backupmanager-provider'
        ),
        '/'
    );

    $storagePath = $storageRoot . '/' . $clientId;

    /*
     * Per client gebruiken we de bestaande storage allocation.
     * Alleen bij de eerste approval wordt er één aangemaakt.
     */
    $storageLookup = $pdo->prepare(
        'SELECT id
         FROM storage_allocations
         WHERE client_id = :client_id
         ORDER BY id ASC
         LIMIT 1
         FOR UPDATE'
    );

    $storageLookup->execute([
        'client_id' => $clientId,
    ]);

    $storage = $storageLookup->fetch();

    if (is_array($storage)) {
        $storageUpdate = $pdo->prepare(
            'UPDATE storage_allocations
             SET destination_type = "ssh",
                 storage_host = :storage_host,
                 storage_port = :storage_port,
                 storage_user = :storage_user,
                 storage_path = :storage_path,
                 status = "active"
             WHERE id = :id'
        );

        $storageUpdate->execute([
            'storage_host' => $config->get('storage', 'host', ''),
            'storage_port' => (int)$config->get('storage', 'port', 22),
            'storage_user' => $config->get('storage', 'user', 'backupstore'),
            'storage_path' => $storagePath,
            'id' => $storage['id'],
        ]);
    } else {
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
            'storage_host' => $config->get('storage', 'host', ''),
            'storage_port' => (int)$config->get('storage', 'port', 22),
            'storage_user' => $config->get('storage', 'user', 'backupstore'),
            'storage_path' => $storagePath,
        ]);
    }

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

    $rotation = bin2hex(random_bytes(32));
    \BackupManager\Provider\Provisioning::install($clientId, (string)$request['public_key'], (string)$request['restore_public_key'], false, $rotation);
    $credentials = $pdo->prepare('UPDATE clients SET api_token_hash = :token WHERE client_id = :client');
    $credentials->execute(['token' => $request['request_token_hash'], 'client' => $clientId]);
    $readKey = $pdo->prepare('UPDATE ssh_keys SET restore_public_key = :key WHERE client_id = :client AND status = "active"');
    $readKey->execute(['key' => $request['restore_public_key'], 'client' => $clientId]);

    $pdo->commit();
    $committed = true;
    try { \BackupManager\Provider\Provisioning::finish($clientId, $rotation, 'commit'); }
    catch (Throwable) { error_log('Backup Manager credential transaction committed in DB but journal finalization needs operator reconciliation: ' . $clientId); }

    echo "APPROVED\n";
    echo "Request ID : {$requestId}\n";
    echo "Client ID  : {$clientId}\n";
    echo "Source     : {$request['source_id']}\n";
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
