<?php
declare(strict_types=1);
namespace BackupManager\Provider;

final class StorageEndpoint {
    public static function validate(mixed $host, mixed $port): array {
        if (!is_string($host) || strlen($host) > 253 || $host !== trim($host)
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host)
            || !(is_int($port) || (is_string($port) && preg_match('/^[0-9]{1,5}$/D', $port)))
            || (int)$port < 1 || (int)$port > 65535) {
            throw new \RuntimeException('storage_not_configured: Configure storage.host and storage.port in /etc/backupmanager-provider/config.php using the client-reachable SSH address and port');
        }
        return ['host' => $host, 'port' => (int)$port];
    }
    public static function connection(Config $config, string $clientId): array {
        $endpoint = self::configured($config);
        if ($config->get('storage', 'user', 'backupstore') !== 'backupstore') {
            throw new \RuntimeException('Managed storage requires the restricted backupstore account');
        }
        // Allocations identify existing storage, not the current public SSH endpoint.
        return ['client_id' => $clientId, 'host' => $endpoint['host'],
            'port' => $endpoint['port'], 'user' => 'backupstore', 'path' => '/'];
    }

    public static function configured(Config $config): array {
        return self::validate($config->get('storage', 'host', ''), $config->get('storage', 'port', 22));
    }
}
