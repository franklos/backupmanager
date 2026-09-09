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
            $raw = file_get_contents('php://input');
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

            if ($sourceId === '') {
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

            $approvalToken = bin2hex(random_bytes(32));
            $approvalTokenHash = hash('sha256', $approvalToken);

            $expiryHours = max(
                1,
                (int)$this->config->get('api', 'request_expiry_hours', 24)
            );

            $expiresAt = gmdate(
                'Y-m-d H:i:s',
                time() + ($expiryHours * 3600)
            );

            $approvalTokenExpiresAt = $expiresAt;

            $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;

            $pdo = $this->database->pdo();
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
                'approval_token' => $approvalToken,
                'status' => 'pending',
                'expires_at' => $expiresAt,
            ], 201);
        } catch (Throwable $e) {
            error_log('Backup Manager provider create error: ' . $e->getMessage());

            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
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
            $update = $this->database->pdo()->prepare(
                'UPDATE provider_requests
                 SET status = "expired"
                 WHERE request_id = :request_id
                   AND status = "pending"'
            );

            $update->execute([
                'request_id' => $requestId,
            ]);

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
            'SELECT
                c.client_id,
                c.source_id,
                pr.request_token_hash
             FROM clients c
             JOIN provider_requests pr
               ON pr.source_id = c.source_id
              AND pr.status = "approved"
             WHERE c.client_id = :client_id
               AND c.status = "active"
             ORDER BY pr.id DESC
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
            'SELECT
                c.client_id,
                pr.request_token_hash
             FROM clients c
             JOIN provider_requests pr
               ON pr.source_id = c.source_id
              AND pr.status = "approved"
             WHERE c.client_id = :client_id
               AND c.status = "active"
             ORDER BY pr.id DESC
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
            'status' => 'connected',
            'capacity_bytes' => $storage['capacity_bytes'],
            'used_bytes' => $storage['used_bytes'],
            'free_bytes' => $storage['free_bytes'],
        ]);
    }


    private function fingerprint(string $publicKey): ?string
    {
        $parts = preg_split('/\s+/', trim($publicKey));

        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        $decoded = base64_decode($parts[1], true);

        if ($decoded === false) {
            return null;
        }

        return 'SHA256:' . rtrim(
            base64_encode(hash('sha256', $decoded, true)),
            '='
        );
    }

    public function createRecovery(): never
    {
        try {
            $raw = file_get_contents('php://input');
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

            if ($sourceId === '') {
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
            $update = $this->database->pdo()->prepare(
                'UPDATE recovery_requests
                 SET status = "expired"
                 WHERE recovery_request_id = :request_id
                   AND status = "pending"'
            );

            $update->execute([
                'request_id' => $requestId,
            ]);

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



    public function approvalAction(string $requestId, string $action): void
    {
        if (
            preg_match('/^REQ-[0-9]{8}-[A-F0-9]{6}$/', $requestId) !== 1
            || !in_array($action, ['approve', 'reject'], true)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Invalid approval request',
            ], 400);
        }

        $token = trim((string)($_GET['token'] ?? ''));

        if ($token === '') {
            Response::json([
                'success' => false,
                'error' => 'Missing approval token',
            ], 400);
        }

        $pdo = $this->database->pdo();

        $statement = $pdo->prepare(
            'SELECT
                request_id,
                status,
                approval_token_hash,
                approval_token_expires_at,
                approval_token_used_at
             FROM provider_requests
             WHERE request_id = :request_id
             LIMIT 1'
        );

        $statement->execute([
            'request_id' => $requestId,
        ]);

        $request = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($request)) {
            Response::json([
                'success' => false,
                'error' => 'Approval request not found',
            ], 404);
        }

        if ((string)$request['status'] !== 'pending') {
            Response::json([
                'success' => false,
                'error' => 'Request is no longer pending',
            ], 409);
        }

        if (!empty($request['approval_token_used_at'])) {
            Response::json([
                'success' => false,
                'error' => 'Approval token has already been used',
            ], 410);
        }

        if (
            empty($request['approval_token_expires_at'])
            || strtotime((string)$request['approval_token_expires_at']) < time()
        ) {
            Response::json([
                'success' => false,
                'error' => 'Approval token has expired',
            ], 410);
        }

        $tokenHash = hash('sha256', $token);

        if (
            empty($request['approval_token_hash'])
            || !hash_equals((string)$request['approval_token_hash'], $tokenHash)
        ) {
            Response::json([
                'success' => false,
                'error' => 'Invalid approval token',
            ], 403);
        }

        $claim = $pdo->prepare(
            'UPDATE provider_requests
             SET approval_token_used_at = UTC_TIMESTAMP()
             WHERE request_id = :request_id
               AND status = "pending"
               AND approval_token_used_at IS NULL
               AND approval_token_expires_at >= UTC_TIMESTAMP()
               AND approval_token_hash = :approval_token_hash'
        );

        $claim->execute([
            'request_id' => $requestId,
            'approval_token_hash' => $tokenHash,
        ]);

        if ($claim->rowCount() !== 1) {
            Response::json([
                'success' => false,
                'error' => 'Approval token is no longer valid',
            ], 409);
        }

        $script = $action === 'approve'
            ? dirname(__DIR__, 2) . '/bin/approve.php'
            : dirname(__DIR__, 2) . '/bin/reject-request.php';

        $process = proc_open(
            ['/usr/bin/php', $script, $requestId, 'email-approval'],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            $cleanup = $pdo->prepare(
                'UPDATE provider_requests
                 SET status = "rejected",
                     rejected_at = UTC_TIMESTAMP(),
                     notes = CONCAT_WS("\n", NULLIF(notes, ""), "Email approval action could not be started")
                 WHERE request_id = :request_id
                   AND status = "pending"'
            );

            $cleanup->execute([
                'request_id' => $requestId,
            ]);

            Response::json([
                'success' => false,
                'error' => 'Unable to start provider action',
            ], 500);
        }

        $stdout = trim((string)stream_get_contents($pipes[1]));
        $stderr = trim((string)stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $cleanup = $pdo->prepare(
                'UPDATE provider_requests
                 SET status = "rejected",
                     rejected_at = UTC_TIMESTAMP(),
                     notes = CONCAT_WS("\n", NULLIF(notes, ""), "Email approval action failed")
                 WHERE request_id = :request_id
                   AND status = "pending"'
            );

            $cleanup->execute([
                'request_id' => $requestId,
            ]);

            error_log(
                'Backup Manager approval action failed for '
                . $requestId
                . ': '
                . ($stderr !== '' ? $stderr : 'unknown error')
            );

            Response::json([
                'success' => false,
                'error' => 'Provider action failed',
            ], 500);
        }

        http_response_code(204);
        exit;
    }

}
