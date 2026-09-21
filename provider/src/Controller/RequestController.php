<?php

declare(strict_types=1);

namespace BackupManager\Provider\Controller;

use BackupManager\Provider\Config;
use BackupManager\Provider\Database;
use BackupManager\Provider\Response;
use PDO;
use Throwable;

final class RequestController
{
    public function __construct(
        private Database $database,
        private Config $config,
    ) {
    }

    public function create(): never
    {
        try {
            $raw = file_get_contents('php://input', false, null, 0, 16385);
            if ($raw === false || strlen($raw) > 16384) {
                Response::json(['success' => false, 'error' => 'Request too large'], 413);
            }
            $input = json_decode($raw ?: '', true);

            if (!is_array($input)) {
                Response::json([
                    'success' => false,
                    'error' => 'Invalid JSON request',
                ], 400);
            }

            $sourceId = trim((string)($input['source_id'] ?? ''));
            $sourceUrl = trim((string)($input['source_url'] ?? ''));
            $publicKey = trim((string)($input['public_key'] ?? ''));
            $clientVersion = trim((string)($input['client_version'] ?? ''));
            $nextcloudVersion = trim((string)($input['nextcloud_version'] ?? ''));
            $requesterEmail = trim((string)($input['requester_email'] ?? ''));

            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,254}$/D', $sourceId)) {
                Response::json([
                    'success' => false,
                    'error' => 'source_id is required',
                ], 400);
            }

            if (
                $requesterEmail === ''
                || filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) === false
            ) {
                Response::json([
                    'success' => false,
                    'error' => 'Valid requester_email is required',
                ], 400);
            }

            if (
                $sourceUrl === ''
                || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
            ) {
                Response::json([
                    'success' => false,
                    'error' => 'Valid source_url is required',
                ], 400);
            }

            if (
                !str_starts_with($publicKey, 'ssh-ed25519 ')
                && !str_starts_with($publicKey, 'ssh-rsa ')
                && !str_starts_with($publicKey, 'ecdsa-sha2-')
            ) {
                Response::json([
                    'success' => false,
                    'error' => 'Valid SSH public key is required',
                ], 400);
            }

            $restoreKey = trim((string)($input['restore_public_key'] ?? ''));
            if ($this->fingerprint($restoreKey) === null || $this->fingerprint($restoreKey) === $this->fingerprint($publicKey)) {
                Response::json(['success' => false, 'error' => 'A distinct valid restore_public_key is required'], 400);
            }
            $fingerprint = $this->fingerprint($publicKey);

            if ($fingerprint === null) {
                Response::json([
                    'success' => false,
                    'error' => 'Unable to calculate SSH fingerprint',
                ], 400);
            }

            $requestId = 'REQ-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $requestToken = bin2hex(random_bytes(32));
            $requestTokenHash = hash('sha256', $requestToken);

            // Enrollment never grants approval authority. Only authenticated administrators approve.
            $approvalTokenHash = null;

            $expiryHours = max(
                1,
                (int)$this->config->get('api', 'request_expiry_hours', 24)
            );

            $expiresAt = gmdate(
                'Y-m-d H:i:s',
                time() + ($expiryHours * 3600)
            );

            $approvalTokenExpiresAt = null;

            $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;

            $pdo = $this->database->pdo();
            $pdo->exec('UPDATE provider_requests SET status = "expired" WHERE status = "pending" AND expires_at < UTC_TIMESTAMP()');
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                'INSERT INTO provider_requests (
                    request_id,
                    request_token_hash,
                    approval_token_hash,
                    approval_token_expires_at,
                    status,
                    source_id,
                    source_url,
                    requester_email,
                    public_key,
                    restore_public_key,
                    ssh_fingerprint,
                    expires_at,
                    requester_ip,
                    client_version,
                    nextcloud_version
                ) VALUES (
                    :request_id,
                    :request_token_hash,
                    :approval_token_hash,
                    :approval_token_expires_at,
                    "pending",
                    :source_id,
                    :source_url,
                    :requester_email,
                    :public_key,
                    :restore_public_key,
                    :ssh_fingerprint,
                    :expires_at,
                    :requester_ip,
                    :client_version,
                    :nextcloud_version
                )'
            );

            $statement->execute([
                'request_id' => $requestId,
                'request_token_hash' => $requestTokenHash,
                'approval_token_hash' => $approvalTokenHash,
                'approval_token_expires_at' => $approvalTokenExpiresAt,
                'source_id' => $sourceId,
                'source_url' => $sourceUrl,
                'requester_email' => $requesterEmail,
                'public_key' => $publicKey,
                'restore_public_key' => $restoreKey,
                'ssh_fingerprint' => $fingerprint,
                'expires_at' => $expiresAt,
                'requester_ip' => $remoteIp,
                'client_version' => $clientVersion !== '' ? $clientVersion : null,
                'nextcloud_version' => $nextcloudVersion !== '' ? $nextcloudVersion : null,
            ]);

            $event = $pdo->prepare(
                'INSERT INTO provider_events (
                    actor_type,
                    actor_id,
                    request_id,
                    event_type,
                    severity,
                    details,
                    remote_ip
                ) VALUES (
                    "client",
                    :actor_id,
                    :request_id,
                    "request.created",
                    "info",
                    :details,
                    :remote_ip
                )'
            );

            $event->execute([
                'actor_id' => $sourceId,
                'request_id' => $requestId,
                'details' => json_encode([
                    'source_url' => $sourceUrl,
                    'ssh_fingerprint' => $fingerprint,
                    'client_version' => $clientVersion,
                    'nextcloud_version' => $nextcloudVersion,
                ], JSON_UNESCAPED_SLASHES),
                'remote_ip' => $remoteIp,
            ]);

            $pdo->commit();

            Response::json([
                'success' => true,
                'request_id' => $requestId,
                'request_token' => $requestToken,
                'status' => 'pending',
                'expires_at' => $expiresAt,
            ], 201);
        } catch (Throwable $e) {
            error_log('Backup Manager provider enrollment failed');

            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof \PDOException && (string)$e->getCode() === '23000') {
                Response::json(['success' => false, 'error' => 'A conflicting or pending request already exists'], 409);
            }
            Response::json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }


    public function status(string $requestId): never
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($token, 'Bearer ')) {
            Response::json([
                'success' => false,
                'error' => 'Missing bearer token',
            ], 401);
        }

        $requestToken = trim(substr($token, 7));

        if ($requestToken === '') {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 401);
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT
                request_id,
                request_token_hash,
                status,
                source_id,
                source_url,
                requested_at,
                approved_at,
                rejected_at,
                expires_at
             FROM provider_requests
             WHERE request_id = :request_id
             LIMIT 1'
        );

        $statement->execute([
            'request_id' => $requestId,
        ]);

        $request = $statement->fetch();

        if (!is_array($request)) {
            Response::json([
                'success' => false,
                'error' => 'Request not found',
            ], 404);
        }

        if (!hash_equals(
            (string)$request['request_token_hash'],
            hash('sha256', $requestToken)
        )) {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 403);
        }

        if (
            $request['status'] === 'pending'
            && !empty($request['expires_at'])
            && strtotime((string)$request['expires_at']) < time()
        ) {

            $request['status'] = 'expired';
        }

        $response = [
            'success' => true,
            'request_id' => $request['request_id'],
            'status' => $request['status'],
            'source_id' => $request['source_id'],
            'source_url' => $request['source_url'],
            'requested_at' => $request['requested_at'],
            'approved_at' => $request['approved_at'],
            'rejected_at' => $request['rejected_at'],
            'expires_at' => $request['expires_at'],
        ];

        if ($request['status'] === 'approved') {
            $connection = $this->database->pdo()->prepare(
                'SELECT
                    c.client_id,
                    s.storage_host,
                    s.storage_port,
                    s.storage_user,
                    s.storage_path
                 FROM clients c
                 JOIN storage_allocations s
                   ON s.client_id = c.client_id
                  AND s.status = "active"
                  AND s.destination_type = "ssh"
                 WHERE c.source_id = :source_id
                   AND c.status = "active"
                 LIMIT 1'
            );

            $connection->execute([
                'source_id' => $request['source_id'],
            ]);

            $connectionData = $connection->fetch();

            if (is_array($connectionData)) {
                $response['connection'] = [
                    'client_id' => $connectionData['client_id'],
                    'host' => $connectionData['storage_host'],
                    'port' => (int)$connectionData['storage_port'],
                    'user' => $connectionData['storage_user'],
                    'path' => '/',
                ];
            }
        }

        Response::json($response);
    }



    public function requestDeletion(string $clientId): never
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($token, 'Bearer ')) {
            Response::json([
                'success' => false,
                'error' => 'Missing bearer token',
            ], 401);
        }

        $requestToken = trim(substr($token, 7));

        if ($requestToken === '') {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 401);
        }

        $pdo = $this->database->pdo();

        $client = $pdo->prepare(
            'SELECT client_id, source_id, api_token_hash AS request_token_hash
             FROM clients WHERE client_id = :client_id AND status IN ("active", "suspended") LIMIT 1'
        );

        $client->execute([
            'client_id' => $clientId,
        ]);

        $clientData = $client->fetch();

        if (!is_array($clientData)) {
            Response::json([
                'success' => false,
                'error' => 'Client not found',
            ], 404);
        }

        if (!hash_equals(
            (string)$clientData['request_token_hash'],
            hash('sha256', $requestToken)
        )) {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 403);
        }

        $existing = $pdo->prepare(
            'SELECT deletion_request_id
             FROM deletion_requests
             WHERE client_id = :client_id
               AND status = "pending"
             LIMIT 1'
        );

        $existing->execute([
            'client_id' => $clientId,
        ]);

        $existingRequest = $existing->fetch();

        if (is_array($existingRequest)) {
            Response::json([
                'success' => true,
                'deletion_request_id' => $existingRequest['deletion_request_id'],
                'status' => 'pending',
            ]);
        }

        $deletionRequestId =
            'DEL-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $insert = $pdo->prepare(
            'INSERT INTO deletion_requests (
                deletion_request_id,
                client_id,
                status,
                delete_storage,
                requested_ip
            ) VALUES (
                :deletion_request_id,
                :client_id,
                "pending",
                1,
                :requested_ip
            )'
        );

        $insert->execute([
            'deletion_request_id' => $deletionRequestId,
            'client_id' => $clientId,
            'requested_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $event = $pdo->prepare(
            'INSERT INTO provider_events (
                actor_type,
                actor_id,
                client_id,
                event_type,
                severity,
                details,
                remote_ip
            ) VALUES (
                "client",
                :actor_id,
                :client_id,
                "deletion.requested",
                "warning",
                :details,
                :remote_ip
            )'
        );

        $event->execute([
            'actor_id' => $clientData['source_id'],
            'client_id' => $clientId,
            'details' => json_encode([
                'deletion_request_id' => $deletionRequestId,
                'delete_storage' => true,
            ], JSON_UNESCAPED_SLASHES),
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        Response::json([
            'success' => true,
            'deletion_request_id' => $deletionRequestId,
            'status' => 'pending',
        ], 201);
    }


    public function managementClients(): never
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($authorization, 'Bearer ')) {
            Response::json([
                'success' => false,
                'error' => 'Missing bearer token',
            ], 401);
        }

        $suppliedToken = trim(substr($authorization, 7));
        $tokenFile = (string)$this->config->get('api', 'management_token_file', '');

        if (
            $suppliedToken === ''
            || $tokenFile === ''
            || !is_readable($tokenFile)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Management authentication unavailable',
            ], 503);
        }

        $expectedToken = trim((string)file_get_contents($tokenFile));

        if (
            $expectedToken === ''
            || !hash_equals($expectedToken, $suppliedToken)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 403);
        }

        $pdo = $this->database->pdo();

        $statement = $pdo->query(
            'SELECT
                client_id,
                source_id,
                source_url,
                status,
                contact_email,
                approved_at
             FROM clients
             ORDER BY client_id ASC'
        );

        Response::json([
            'success' => true,
            'clients' => $statement->fetchAll(),
        ]);
    }


    public function managementClientState(string $clientId, string $action): never
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $tokenFile = (string)$this->config->get('api', 'management_token_file', '');

        if (!str_starts_with($authorization, 'Bearer ')) {
            Response::json([
                'success' => false,
                'error' => 'Missing bearer token',
            ], 401);
        }

        $suppliedToken = trim(substr($authorization, 7));

        if (
            $suppliedToken === ''
            || $tokenFile === ''
            || !is_readable($tokenFile)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Management authentication unavailable',
            ], 503);
        }

        $expectedToken = trim((string)file_get_contents($tokenFile));

        if (
            $expectedToken === ''
            || !hash_equals($expectedToken, $suppliedToken)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 403);
        }

        if (!in_array($action, ['pause', 'resume'], true)) {
            Response::json([
                'success' => false,
                'error' => 'Invalid management action',
            ], 400);
        }

        $pdo = $this->database->pdo();

        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                'SELECT
                    c.status,
                    sk.public_key,
                    sk.restore_public_key,
                    sa.storage_path
                 FROM clients c
                 LEFT JOIN ssh_keys sk
                   ON sk.client_id = c.client_id
                  AND sk.status = "active"
                 LEFT JOIN storage_allocations sa
                   ON sa.client_id = c.client_id
                  AND sa.status IN ("active", "suspended")
                 WHERE c.client_id = :client_id
                 ORDER BY sk.id DESC, sa.id DESC
                 LIMIT 1
                 FOR UPDATE'
            );

            $statement->execute([
                'client_id' => $clientId,
            ]);

            $clientData = $statement->fetch();

            if (!is_array($clientData)) {
                $pdo->rollBack();
                Response::json([
                    'success' => false,
                    'error' => 'Client not found',
                ], 404);
            }

            if ($clientData['status'] === 'terminated') {
                $pdo->rollBack();
                Response::json([
                    'success' => false,
                    'error' => 'Terminated client cannot be paused or resumed',
                ], 409);
            }

            if ($action === 'pause') {
                $command = sprintf(
                    'sudo -n /usr/local/sbin/backupmanager-remove-authorized-key %s',
                    escapeshellarg($clientId)
                );

                exec($command, $output, $exitCode);

                if ($exitCode !== 0) {
                    throw new \RuntimeException('Unable to suspend SSH access');
                }

                $updateClient = $pdo->prepare(
                    'UPDATE clients SET status = "suspended" WHERE client_id = :client_id'
                );
                $updateClient->execute(['client_id' => $clientId]);

                $updateStorage = $pdo->prepare(
                    'UPDATE storage_allocations
                     SET status = "suspended"
                     WHERE client_id = :client_id
                       AND status = "active"'
                );
                $updateStorage->execute(['client_id' => $clientId]);

                $pdo->commit();

                Response::json([
                    'success' => true,
                    'client_id' => $clientId,
                    'status' => 'suspended',
                ]);
            }

            $publicKey = trim((string)($clientData['public_key'] ?? ''));
            $storagePath = trim((string)($clientData['storage_path'] ?? ''));

            if ($publicKey === '' || $storagePath === '') {
                throw new \RuntimeException('Client SSH configuration unavailable');
            }

            $resumeProvisioned = true;
            \BackupManager\Provider\Provisioning::install($clientId, $publicKey, (string)$clientData['restore_public_key']);

            $updateClient = $pdo->prepare(
                'UPDATE clients SET status = "active" WHERE client_id = :client_id'
            );
            $updateClient->execute(['client_id' => $clientId]);

            $updateStorage = $pdo->prepare(
                'UPDATE storage_allocations
                 SET status = "active"
                 WHERE client_id = :client_id
                   AND status = "suspended"'
            );
            $updateStorage->execute(['client_id' => $clientId]);

            $pdo->commit();

            Response::json([
                'success' => true,
                'client_id' => $clientId,
                'status' => 'active',
            ]);
        } catch (\Throwable $e) {
            if (isset($resumeProvisioned)) {
                exec('sudo -n /usr/local/sbin/backupmanager-remove-authorized-key ' . escapeshellarg($clientId), $revokeOutput, $revokeCode);
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::json([
                'success' => false,
                'error' => 'Client state change failed',
            ], 500);
        }
    }


    public function managementRemoveClient(string $clientId): never
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $tokenFile = (string)$this->config->get(
            'api',
            'management_token_file',
            ''
        );

        if (!str_starts_with($authorization, 'Bearer ')) {
            Response::json([
                'success' => false,
                'error' => 'Missing bearer token',
            ], 401);
        }

        $suppliedToken = trim(substr($authorization, 7));

        if (
            $suppliedToken === ''
            || $tokenFile === ''
            || !is_readable($tokenFile)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Management authentication unavailable',
            ], 503);
        }

        $expectedToken = trim((string)file_get_contents($tokenFile));

        if (
            $expectedToken === ''
            || !hash_equals($expectedToken, $suppliedToken)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 403);
        }

        $pdo = $this->database->pdo();

        try {
            $client = $pdo->prepare(
                'SELECT client_id, source_id, status
                 FROM clients
                 WHERE client_id = :client_id
                 LIMIT 1'
            );

            $client->execute([
                'client_id' => $clientId,
            ]);

            $clientData = $client->fetch();

            if (!is_array($clientData)) {
                Response::json([
                    'success' => false,
                    'error' => 'Client not found',
                ], 404);
            }

            if (!in_array(
                $clientData['status'],
                ['active', 'suspended'],
                true
            )) {
                Response::json([
                    'success' => false,
                    'error' => 'Client cannot be removed in current state',
                ], 409);
            }

            $deletionRequestId =
                'DEL-' . gmdate('Ymd') . '-'
                . strtoupper(bin2hex(random_bytes(3)));

            $insert = $pdo->prepare(
                'INSERT INTO deletion_requests (
                    deletion_request_id,
                    client_id,
                    status,
                    delete_storage,
                    requested_ip
                ) VALUES (
                    :deletion_request_id,
                    :client_id,
                    "pending",
                    0,
                    :requested_ip
                )'
            );

            $insert->execute([
                'deletion_request_id' => $deletionRequestId,
                'client_id' => $clientId,
                'requested_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $command = sprintf(
                'php %s %s %s 2>&1',
                escapeshellarg(
                    dirname(__DIR__, 2) . '/bin/approve-deletion.php'
                ),
                escapeshellarg($deletionRequestId),
                escapeshellarg('management')
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                Response::json([
                    'success' => false,
                    'error' => 'Removal failed',
                    'deletion_request_id' => $deletionRequestId,
                ], 500);
            }

            Response::json([
                'success' => true,
                'client_id' => $clientId,
                'status' => 'terminated',
                'storage_deleted' => false,
            ]);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'error' => 'Removal failed',
            ], 500);
        }
    }


    public function managementDeleteClient(string $clientId): never
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $tokenFile = (string)$this->config->get('api', 'management_token_file', '');

        if (!str_starts_with($authorization, 'Bearer ')) {
            Response::json([
                'success' => false,
                'error' => 'Missing bearer token',
            ], 401);
        }

        $suppliedToken = trim(substr($authorization, 7));

        if (
            $suppliedToken === ''
            || $tokenFile === ''
            || !is_readable($tokenFile)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Management authentication unavailable',
            ], 503);
        }

        $expectedToken = trim((string)file_get_contents($tokenFile));

        if (
            $expectedToken === ''
            || !hash_equals($expectedToken, $suppliedToken)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 403);
        }

        $pdo = $this->database->pdo();

        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                'SELECT client_id, source_id, status
                 FROM clients
                 WHERE client_id = :client_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['client_id' => $clientId]);

            $client = $statement->fetch();

            if (!is_array($client)) {
                $pdo->rollBack();

                Response::json([
                    'success' => false,
                    'error' => 'Client not found',
                ], 404);
            }

            if ($client['status'] !== 'terminated') {
                $pdo->rollBack();

                Response::json([
                    'success' => false,
                    'error' => 'Only terminated clients can be permanently deleted',
                ], 409);
            }

            $removeStorage = sprintf(
                'sudo -n /usr/local/sbin/backupmanager-remove-storage %s',
                escapeshellarg($clientId)
            );

            exec($removeStorage, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    'Unable to remove client backup data'
                );
            }

            $event = $pdo->prepare(
                'INSERT INTO provider_events
                    (actor_type, actor_id, client_id, event_type, severity, details)
                 VALUES
                    ("administrator", "management", :client_id, "client.deleted", "warning", :details)'
            );

            $event->execute([
                'client_id' => $clientId,
                'details' => json_encode([
                    'client_id' => $clientId,
                    'source_id' => $client['source_id'],
                ], JSON_UNESCAPED_SLASHES),
            ]);

            $delete = $pdo->prepare(
                'DELETE FROM clients WHERE client_id = :client_id'
            );
            $delete->execute(['client_id' => $clientId]);

            $pdo->commit();

            Response::json([
                'success' => true,
                'client_id' => $clientId,
                'status' => 'deleted',
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::json([
                'success' => false,
                'error' => 'Client deletion failed',
            ], 500);
        }
    }


    public function clientStatus(string $clientId): never
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($token, 'Bearer ')) {
            Response::json([
                'success' => false,
                'error' => 'Missing bearer token',
            ], 401);
        }

        $requestToken = trim(substr($token, 7));

        if ($requestToken === '') {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 401);
        }

        $pdo = $this->database->pdo();

        $client = $pdo->prepare(
            'SELECT client_id, status, api_token_hash AS request_token_hash
             FROM clients WHERE client_id = :client_id AND status IN ("active", "suspended") LIMIT 1'
        );

        $client->execute([
            'client_id' => $clientId,
        ]);

        $clientData = $client->fetch();

        if (!is_array($clientData)) {
            Response::json([
                'success' => false,
                'error' => 'Client not found',
            ], 404);
        }

        if (!hash_equals(
            (string)$clientData['request_token_hash'],
            hash('sha256', $requestToken)
        )) {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 403);
        }

        $command = sprintf(
            'sudo -n /usr/local/sbin/backupmanager-storage-usage %s',
            escapeshellarg($clientId)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            Response::json([
                'success' => false,
                'status' => 'unavailable',
                'error' => 'Unable to determine storage status',
            ], 503);
        }

        $storage = [];

        foreach ($output as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            if (in_array($key, [
                'capacity_bytes',
                'used_bytes',
                'free_bytes',
            ], true) && ctype_digit($value)) {
                $storage[$key] = (int)$value;
            }
        }

        if (
            !isset(
                $storage['capacity_bytes'],
                $storage['used_bytes'],
                $storage['free_bytes']
            )
        ) {
            Response::json([
                'success' => false,
                'status' => 'unavailable',
                'error' => 'Invalid storage status',
            ], 503);
        }

        Response::json([
            'success' => true,
            'status' => $clientData['status'] === 'active' ? 'connected' : 'suspended',
            'capacity_bytes' => $storage['capacity_bytes'],
            'used_bytes' => $storage['used_bytes'],
            'free_bytes' => $storage['free_bytes'],
        ]);
    }


    private function fingerprint(string $publicKey): ?string
    {
        if (preg_match('/^ssh-ed25519 ([A-Za-z0-9+\/=]+)(?: [^\r\n]*)?$/D', $publicKey, $m) !== 1) {
            return null;
        }
        $decoded = base64_decode($m[1], true);
        if ($decoded === false || strlen($decoded) !== 51
            || substr($decoded, 0, 19) !== pack('N', 11) . 'ssh-ed25519' . pack('N', 32)) {
            return null;
        }
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $decoded, true)), '=');
    }

    public function createRecovery(): never
    {
        try {
            $raw = file_get_contents('php://input', false, null, 0, 16385);
            if ($raw === false || strlen($raw) > 16384) {
                Response::json(['success' => false, 'error' => 'Request too large'], 413);
            }
            $input = json_decode($raw ?: '', true);

            if (!is_array($input)) {
                Response::json([
                    'success' => false,
                    'error' => 'Invalid JSON request',
                ], 400);
            }

            $clientId = trim((string)($input['client_id'] ?? ''));
            $sourceId = trim((string)($input['source_id'] ?? ''));
            $publicKey = trim((string)($input['public_key'] ?? ''));

            if (preg_match('/^BM-[0-9]{6}$/', $clientId) !== 1) {
                Response::json([
                    'success' => false,
                    'error' => 'Valid client_id is required',
                ], 400);
            }

            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,254}$/D', $sourceId)) {
                Response::json([
                    'success' => false,
                    'error' => 'source_id is required',
                ], 400);
            }

            if (
                !str_starts_with($publicKey, 'ssh-ed25519 ')
                && !str_starts_with($publicKey, 'ssh-rsa ')
                && !str_starts_with($publicKey, 'ecdsa-sha2-')
            ) {
                Response::json([
                    'success' => false,
                    'error' => 'Valid SSH public key is required',
                ], 400);
            }

            $restoreKey = trim((string)($input['restore_public_key'] ?? ''));
            if ($this->fingerprint($restoreKey) === null || $this->fingerprint($restoreKey) === $this->fingerprint($publicKey)) {
                Response::json(['success' => false, 'error' => 'A distinct valid restore_public_key is required'], 400);
            }
            $fingerprint = $this->fingerprint($publicKey);

            if ($fingerprint === null) {
                Response::json([
                    'success' => false,
                    'error' => 'Unable to calculate SSH fingerprint',
                ], 400);
            }

            $pdo = $this->database->pdo();

            $client = $pdo->prepare(
                'SELECT client_id, source_id, status
                 FROM clients
                 WHERE client_id = :client_id
                 LIMIT 1'
            );

            $client->execute([
                'client_id' => $clientId,
            ]);

            $clientData = $client->fetch();

            if (!is_array($clientData) || $clientData['status'] !== 'active') {
                Response::json([
                    'success' => false,
                    'error' => 'Active client not found',
                ], 404);
            }

            $pdo->exec('UPDATE recovery_requests SET status = "expired" WHERE status = "pending" AND expires_at < UTC_TIMESTAMP()');
            $existing = $pdo->prepare(
                'SELECT recovery_request_id
                 FROM recovery_requests
                 WHERE client_id = :client_id
                   AND status = "pending"
                 LIMIT 1'
            );

            $existing->execute([
                'client_id' => $clientId,
            ]);

            $existingRequest = $existing->fetch();

            if (is_array($existingRequest)) {
                Response::json([
                    'success' => false,
                    'error' => 'A recovery request is already pending',
                ], 409);
            }

            $requestId =
                'REC-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $requestToken = bin2hex(random_bytes(32));
            $requestTokenHash = hash('sha256', $requestToken);

            $expiryHours = max(
                1,
                (int)$this->config->get('api', 'request_expiry_hours', 24)
            );

            $expiresAt = gmdate(
                'Y-m-d H:i:s',
                time() + ($expiryHours * 3600)
            );

            $pdo->beginTransaction();

            $insert = $pdo->prepare(
                'INSERT INTO recovery_requests (
                    recovery_request_id,
                    request_token_hash,
                    client_id,
                    source_id,
                    public_key,
                    restore_public_key,
                    ssh_fingerprint,
                    status,
                    expires_at,
                    requester_ip
                 ) VALUES (
                    :recovery_request_id,
                    :request_token_hash,
                    :client_id,
                    :source_id,
                    :public_key,
                    :restore_public_key,
                    :ssh_fingerprint,
                    "pending",
                    :expires_at,
                    :requester_ip
                 )'
            );

            $insert->execute([
                'recovery_request_id' => $requestId,
                'request_token_hash' => $requestTokenHash,
                'client_id' => $clientId,
                'source_id' => $sourceId,
                'public_key' => $publicKey,
                'restore_public_key' => $restoreKey,
                'ssh_fingerprint' => $fingerprint,
                'expires_at' => $expiresAt,
                'requester_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $event = $pdo->prepare(
                'INSERT INTO provider_events (
                    actor_type,
                    actor_id,
                    client_id,
                    event_type,
                    severity,
                    details,
                    remote_ip
                 ) VALUES (
                    "client",
                    :actor_id,
                    :client_id,
                    "recovery.requested",
                    "security",
                    :details,
                    :remote_ip
                 )'
            );

            $event->execute([
                'actor_id' => $sourceId,
                'client_id' => $clientId,
                'details' => json_encode([
                    'recovery_request_id' => $requestId,
                    'ssh_fingerprint' => $fingerprint,
                ], JSON_UNESCAPED_SLASHES),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $pdo->commit();

            Response::json([
                'success' => true,
                'recovery_request_id' => $requestId,
                'request_token' => $requestToken,
                'status' => 'pending',
                'expires_at' => $expiresAt,
            ], 201);

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof \PDOException && (string)$e->getCode() === '23000') {
                Response::json(['success' => false, 'error' => 'A conflicting or pending request already exists'], 409);
            }
            Response::json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    public function recoveryStatus(string $requestId): never
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($token, 'Bearer ')) {
            Response::json([
                'success' => false,
                'error' => 'Missing bearer token',
            ], 401);
        }

        $requestToken = trim(substr($token, 7));

        $statement = $this->database->pdo()->prepare(
            'SELECT
                recovery_request_id,
                request_token_hash,
                client_id,
                source_id,
                status,
                requested_at,
                approved_at,
                rejected_at,
                expires_at
             FROM recovery_requests
             WHERE recovery_request_id = :request_id
             LIMIT 1'
        );

        $statement->execute([
            'request_id' => $requestId,
        ]);

        $request = $statement->fetch();

        if (!is_array($request)) {
            Response::json([
                'success' => false,
                'error' => 'Recovery request not found',
            ], 404);
        }

        if (!hash_equals(
            (string)$request['request_token_hash'],
            hash('sha256', $requestToken)
        )) {
            Response::json([
                'success' => false,
                'error' => 'Invalid bearer token',
            ], 403);
        }

        if (
            $request['status'] === 'pending'
            && !empty($request['expires_at'])
            && strtotime((string)$request['expires_at']) < time()
        ) {

            $request['status'] = 'expired';
        }

        $response = [
            'success' => true,
            'recovery_request_id' => $request['recovery_request_id'],
            'client_id' => $request['client_id'],
            'source_id' => $request['source_id'],
            'status' => $request['status'],
            'requested_at' => $request['requested_at'],
            'approved_at' => $request['approved_at'],
            'rejected_at' => $request['rejected_at'],
            'expires_at' => $request['expires_at'],
        ];

        if ($request['status'] === 'approved') {
            $connection = $this->database->pdo()->prepare(
                'SELECT
                    c.client_id,
                    s.storage_host,
                    s.storage_port,
                    s.storage_user
                 FROM clients c
                 JOIN storage_allocations s
                   ON s.client_id = c.client_id
                  AND s.status = "active"
                  AND s.destination_type = "ssh"
                 WHERE c.client_id = :client_id
                   AND c.status = "active"
                 LIMIT 1'
            );

            $connection->execute([
                'client_id' => $request['client_id'],
            ]);

            $connectionData = $connection->fetch();

            if (is_array($connectionData)) {
                $response['connection'] = [
                    'client_id' => $connectionData['client_id'],
                    'host' => $connectionData['storage_host'],
                    'port' => (int)$connectionData['storage_port'],
                    'user' => $connectionData['storage_user'],
                    'path' => '/',
                ];
            }
        }

        Response::json($response);
    }



}
