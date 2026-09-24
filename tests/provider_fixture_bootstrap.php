<?php
declare(strict_types=1);
namespace BackupManager\Provider;
// Test-only database dependency injection. Never load /etc or repository configuration.
final class Config {
    public function __construct(string $unused = '') {}
    public function get(string $section, string $key, mixed $default = null): mixed {
        return ['storage' => ['host' => (getenv('BM_TEST_STORAGE_HOST') ?: (getenv('BM_JOURNEY_ROOT') ? '127.0.0.1' : 'storage.example.test')), 'port' => (int)(getenv('BM_JOURNEY_PORT') ?: 22), 'user' => 'backupstore', 'root' => '/var/lib/backupmanager-provider'],
            'api' => ['request_expiry_hours' => 24]][$section][$key] ?? $default;
    }
}
final class Database {
    private \PDO $connection;
    public function __construct(Config $unused) {
        $socket = getenv('BM_TEST_SOCKET');
        if (!is_string($socket) || !preg_match('#^/tmp/bm-integration-[A-Za-z0-9_-]+/server.sock$#D', $socket)) {
            throw new \RuntimeException('Only the isolated fixture socket is allowed');
        }
        $this->connection = new \PDO('mysql:unix_socket=' . $socket . ';dbname=' . (getenv('BM_JOURNEY_ROOT') ? 'bm_journey' : 'bm_fixture'), 'root', '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    public function pdo(): \PDO { return $this->connection; }
}
