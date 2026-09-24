<?php
declare(strict_types=1);
namespace BackupManager\Provider;
// CLI fixture configuration only; use the real Database and migrate.php implementations.
final class Config {
    public function __construct(string $unused = '') {
        if (!preg_match('#^/tmp/bm-integration-[A-Za-z0-9_-]+/server.sock$#D', (string)getenv('BM_TEST_SOCKET'))) {
            throw new \RuntimeException('Only the isolated fixture socket is allowed');
        }
    }
    public function get(string $section, string $key, mixed $default = null): mixed {
        return ['database' => ['host' => 'localhost;unix_socket=' . getenv('BM_TEST_SOCKET'),
            'name' => 'bm_fixture', 'user' => 'bm_runtime', 'password' => 'fixture-private-password']][$section][$key] ?? $default;
    }
}
