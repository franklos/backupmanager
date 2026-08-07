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
$pdo = $db->pdo();

$stmt = $pdo->prepare(
    'SELECT
        c.client_id,
        c.status,
        k.public_key,
        k.fingerprint,
        s.storage_path
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

$stmt->execute([
    'client_id' => $clientId,
]);

$row = $stmt->fetch();

if (!is_array($row)) {
    fail('Active client/key/storage combination not found');
}

$storageRoot = realpath(
    (string)$config->get('storage', 'root', '/var/lib/backupmanager-provider')
);

$storagePath = realpath((string)$row['storage_path']);

if ($storageRoot === false || $storagePath === false) {
    fail('Storage path does not exist');
}

if ($storagePath !== $storageRoot . '/' . $clientId) {
    fail('Unsafe storage path');
}

$rrsync = '/usr/bin/rrsync';

if (!is_executable($rrsync)) {
    fail('/usr/bin/rrsync not found');
}

$forcedCommand = sprintf(
    '%s -wo %s',
    $rrsync,
    escapeshellarg($storagePath)
);

$key = trim((string)$row['public_key']);

$authorizedLine = sprintf(
    'restrict,command="%s" %s',
    str_replace(
        ['\\', '"'],
        ['\\\\', '\\"'],
        $forcedCommand
    ),
    $key
);

$file = '/var/lib/backupmanager-provider/.ssh/authorized_keys';

$current = is_file($file)
    ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

if ($current === false) {
    fail('Unable to read authorized_keys');
}

/* Verwijder eventuele bestaande entry voor exact dezelfde key. */
$current = array_values(array_filter(
    $current,
    static fn(string $line): bool => !str_contains($line, $key)
));

$current[] = $authorizedLine;

$tmp = tempnam('/tmp', 'bm-auth-');

if ($tmp === false) {
    fail('Unable to create temporary file');
}

if (
    file_put_contents(
        $tmp,
        implode(PHP_EOL, $current) . PHP_EOL,
        LOCK_EX
    ) === false
) {
    @unlink($tmp);
    fail('Unable to write temporary authorized_keys');
}

echo $tmp . PHP_EOL;
