<?php
// Compatibility entry point. Uses the same validated two-key provisioner as approval.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(function ($class) {
    $prefix = 'BackupManager\\Provider\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$id = (string)($argv[1] ?? '');
if (!preg_match('/^BM-[0-9]{6}$/D', $id)) { exit(1); }
$config = new BackupManager\Provider\Config();
$pdo = (new BackupManager\Provider\Database($config))->pdo();
$query = $pdo->prepare('SELECT k.public_key, k.restore_public_key FROM ssh_keys k JOIN clients c ON c.client_id=k.client_id WHERE k.client_id=? AND k.status="active" AND c.status="active"');
$query->execute([$id]);
$row = $query->fetch();
if (!$row) { exit(1); }
BackupManager\Provider\Provisioning::install($id, $row['public_key'], (string)$row['restore_public_key']);
