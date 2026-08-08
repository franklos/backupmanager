<?php
declare(strict_types=1);

namespace OCA\BackupStatus\Service\Removal;

use OCP\Http\Client\IClientService;
use Throwable;

final class ProviderRemovalService implements RemoteRemovalInterface {
    public function __construct(
        private IClientService $clientService,
        private string $providerUrl,
        private string $clientId,
        private string $requestToken,
    ) {
    }

    public function remove(): RemovalResult {
        if (
            $this->providerUrl === ''
            || $this->clientId === ''
            || $this->requestToken === ''
        ) {
            return RemovalResult::failure(
                'Provider registration is incomplete'
            );
        }

        try {
            $client = $this->clientService->newClient();

            $response = $client->post(
                rtrim($this->providerUrl, '/')
                    . '/api/v1/clients/'
                    . rawurlencode($this->clientId)
                    . '/deletion-requests',
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->requestToken,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode([
                        'client_id' => $this->clientId,
                        'delete_storage' => true,
                    ], JSON_UNESCAPED_SLASHES),
                    'timeout' => 30,
                ]
            );

            $data = json_decode((string)$response->getBody(), true);

            if (
                !is_array($data)
                || !($data['success'] ?? false)
                || empty($data['deletion_request_id'])
            ) {
                return RemovalResult::failure(
                    'Invalid response from backup provider'
                );
            }

            return RemovalResult::pending(
                'Deletion request '
                . $data['deletion_request_id']
                . ' is awaiting administrator approval'
            );
        } catch (Throwable $e) {
            return RemovalResult::failure(
                'Provider deletion request failed: ' . $e->getMessage()
            );
        }
    }
}
