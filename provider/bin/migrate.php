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
use BackupManager\Provider\Migrations;
use BackupManager\Provider\MigrationFailure;
try {
    $check = false;
    $credentials = null;
    for ($i = 1; $i < $argc; $i++) {
        if ($argv[$i] === '--check') { $check = true; }
        elseif ($argv[$i] === '--credentials' && isset($argv[$i + 1])) { $credentials = $argv[++$i]; }
        else { throw new MigrationFailure('Usage: migrate.php [--check] [--credentials /absolute/private/file.json]'); }
    }
    $config = new BackupManager\Provider\Config();
    if ($credentials === null) {
        $pdo = (new BackupManager\Provider\Database($config))->pdo();
    } else {
        // Override credentials only, never the configured database target or runtime config.
        $stat = @lstat($credentials);
        if (!str_starts_with($credentials, '/') || $stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0) {
            throw new MigrationFailure('Migration credentials must be an absolute path to a regular private file (mode 0600 or 0400).');
        }
        $auth = json_decode((string)file_get_contents($credentials), true);
        if (!is_array($auth) || array_diff(array_keys($auth), ['user', 'password']) || !is_string($auth['user'] ?? null) || !is_string($auth['password'] ?? null)) {
            throw new MigrationFailure('Migration credentials must be JSON containing only string user and password fields.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->get('database', 'host', 'localhost'), (int)$config->get('database', 'port', 3306),
            $config->get('database', 'name'), $config->get('database', 'charset', 'utf8mb4'));
        $pdo = new PDO($dsn, $auth['user'], $auth['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        unset($auth);
    }
    $plan = Migrations::run($pdo, dirname(__DIR__) . '/sql/schema.sql', $check,
        static function (string $message): void { echo $message . "\n"; });
    if ($check && $plan !== []) {
        echo "Changes pending; inspection only, no changes made. Review the operations and use deployment credentials if DDL is needed.\n";
        exit(2);
    }
    echo $plan === [] ? "Schema and data migrations are up to date; no changes required.\n"
        : "Schema migration completed. Existing storage and client records preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration stopped: ' . ($error instanceof MigrationFailure ? $error->getMessage() : Migrations::describe($error)) . "\n");
    fwrite(STDERR, "Completed DDL may already be committed. Inspect with --check before retrying; keep the deployment window open.\n");
    exit(1);
}
