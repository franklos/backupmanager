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

            if ($sourceId === '') {
                Response::json([
                    'success' => false,
                    'error' => 'source_id is required',
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

            $expiryHours = max(
                1,
                (int)$this->config->get('api', 'request_expiry_hours', 24)
            );

            $expiresAt = gmdate(
                'Y-m-d H:i:s',
                time() + ($expiryHours * 3600)
            );

            $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;

            $pdo = $this->database->pdo();
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                'INSERT INTO provider_requests (
                    request_id,
                    request_token_hash,
                    status,
                    source_id,
                    source_url,
                    public_key,
                    ssh_fingerprint,
                    expires_at,
                    requester_ip,
                    client_version,
                    nextcloud_version
                ) VALUES (
                    :request_id,
                    :request_token_hash,
                    "pending",
                    :source_id,
                    :source_url,
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
                'source_id' => $sourceId,
                'source_url' => $sourceUrl,
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

        Response::json([
            'success' => true,
            'request_id' => $request['request_id'],
            'status' => $request['status'],
            'source_id' => $request['source_id'],
            'source_url' => $request['source_url'],
            'requested_at' => $request['requested_at'],
            'approved_at' => $request['approved_at'],
            'rejected_at' => $request['rejected_at'],
            'expires_at' => $request['expires_at'],
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
}
