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

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

$clientId = trim((string)($argv[1] ?? ''));

if (!preg_match('/^BM-[0-9]{6}$/', $clientId)) {
    fail('Usage: php provision-ssh.php BM-000001');
}

$config = new Config(dirname(__DIR__) . '/config/config.php');
$db = new Database($config);

$stmt = $db->pdo()->prepare(
    'SELECT k.public_key, s.storage_path
     FROM clients c
     JOIN ssh_keys k
       ON k.client_id = c.client_id
      AND k.status = "active"
     JOIN storage_allocations s
       ON s.client_id = c.client_id
      AND s.status = "active"
      AND s.destination_type = "ssh"
     WHERE c.client_id = :client_id
       AND c.status = "active"
     LIMIT 1'
);

$stmt->execute(['client_id' => $clientId]);
$row = $stmt->fetch();

if (!is_array($row)) {
    fail('Active client/key/storage combination not found');
}

$storageRoot = realpath(
    (string)$config->get('storage', 'root', '/var/lib/backupmanager-provider')
);
$storagePath = realpath((string)$row['storage_path']);

if (
    $storageRoot === false
    || $storagePath === false
    || $storagePath !== $storageRoot . '/' . $clientId
) {
    fail('Unsafe or missing storage path');
}

if (!is_executable('/usr/bin/rrsync')) {
    fail('/usr/bin/rrsync not found');
}

$keyParts = preg_split('/\s+/', trim((string)$row['public_key']));

if (!is_array($keyParts) || count($keyParts) < 2) {
    fail('Invalid public key');
}

$key = $keyParts[0] . ' ' . $keyParts[1];

$forcedCommand = '/usr/bin/rrsync -wo ' . escapeshellarg($storagePath);

$line = sprintf(
    'restrict,command="%s" %s bm-client=%s',
    str_replace(['\\', '"'], ['\\\\', '\\"'], $forcedCommand),
    $key,
    $clientId
);

$tmp = tempnam('/tmp', 'bm-auth-');

if ($tmp === false || file_put_contents($tmp, $line . PHP_EOL, LOCK_EX) === false) {
    fail('Unable to create temporary authorization file');
}

echo $tmp . PHP_EOL;
