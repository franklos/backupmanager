<?php
declare(strict_types=1);
// Invoke explicitly during deployment; application requests never migrate a database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(function (string $class): void {
    $prefix = 'BackupManager\\Provider\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
try {
    $config = new BackupManager\Provider\Config(dirname(__DIR__) . '/config/config.php');
    $pdo = (new BackupManager\Provider\Database($config))->pdo();
    BackupManager\Provider\Migrations::run($pdo, dirname(__DIR__) . '/sql/schema.sql');
    echo "Schema migration completed. Existing storage and client records preserved.\n";
} catch (Throwable $error) {
    // Database driver errors may include sensitive connection information.
    fwrite(STDERR, "Migration stopped. Check schema privileges and duplicate records; completed DDL is safe to retry.\n");
    exit(1);
}
