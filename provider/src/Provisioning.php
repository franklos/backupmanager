<?php
declare(strict_types=1);
namespace BackupManager\Provider;

final class Provisioning {
    public static function install(string $clientId, string $writeKey, string $readKey, bool $existingOnly = false, string $transaction = ''): void {
        if (!preg_match('/^BM-[0-9]{6}$/D', $clientId) || $readKey === '' || $readKey === $writeKey) {
            throw new \RuntimeException('Re-enrollment with distinct read/write keys is required');
        }
        self::send(['client_id' => $clientId, 'write_key' => $writeKey, 'read_key' => $readKey,
            'existing_only' => $existingOnly, 'transaction' => $transaction]);
    }
    public static function finish(string $clientId, string $transaction, string $operation): void {
        if (!in_array($operation, ['commit', 'rollback'], true)) { throw new \RuntimeException('Invalid credential transaction'); }
        self::send(['client_id' => $clientId, 'transaction' => $transaction, 'operation' => $operation]);
    }
    private static function send(array $payload): void {
        $process = proc_open(['sudo', '-n', '/usr/local/sbin/backupmanager-install-authorized-keys'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Key provisioning unavailable');
        }
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('Key provisioning failed');
        }
    }
}
