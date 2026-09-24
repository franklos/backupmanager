<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(static function ($class) {
    $prefix = 'BackupManager\\Provider\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
try {
    $config = new BackupManager\Provider\Config();
    $endpoint = BackupManager\Provider\StorageEndpoint::configured($config);
    $result = ['configuration_file' => BackupManager\Provider\Config::FILE, 'storage' => $endpoint];
    if (($argv[1] ?? '') !== '') {
        if (($argv[1] ?? '') !== '--client' || !preg_match('/^BM-[0-9]{6}$/D', $argv[2] ?? '') || count($argv) !== 3) {
            throw new RuntimeException('Usage: check-config.php [--client BM-000000]');
        }
        $pdo = (new BackupManager\Provider\Database($config))->pdo();
        $statement = $pdo->prepare('SELECT client_id, storage_host, storage_port, storage_path FROM storage_allocations WHERE client_id=? AND status="active" AND destination_type="ssh"');
        $statement->execute([$argv[2]]);
        $allocation = $statement->fetch();
        if (!$allocation) { throw new RuntimeException('Active allocation not found'); }
        BackupManager\Provider\StorageEndpoint::validate($allocation['storage_host'], $allocation['storage_port']);
        $result['existing_allocation'] = $allocation;
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Provider configuration check failed: " . $error->getMessage() . "\n");
    exit(2);
}
