<?php
declare(strict_types=1);
// Isolated authorization tests. No database, live config or privileged helpers are used.
namespace BackupManager\Provider {
    final class JsonExit extends \RuntimeException {
        public function __construct(public array $data, public int $status) { parent::__construct('JSON response'); }
    }
    final class Response {
        public static function json(array $data, int $status = 200): never { throw new JsonExit($data, $status); }
    }
}
namespace {
    $root = dirname(__DIR__);
    require $root . '/provider/src/Config.php';
    require $root . '/provider/src/Database.php';
    require $root . '/provider/src/Controller/RequestController.php';
    // Reflection avoids loading any real configuration or opening a PDO connection.
    $database = (new ReflectionClass(BackupManager\Provider\Database::class))->newInstanceWithoutConstructor();
    $config = (new ReflectionClass(BackupManager\Provider\Config::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty($config, 'config');
    $property->setValue($config, []);
    $controller = new BackupManager\Provider\Controller\RequestController($database, $config);
    foreach ([['status', 'REQ-fixture'], ['requestDeletion', 'BM-000001'], ['clientStatus', 'BM-000001'],
              ['managementClients'], ['managementClientState', 'BM-000001', 'pause'],
              ['managementRemoveClient', 'BM-000001'], ['managementDeleteClient', 'BM-000001'],
              ['recoveryStatus', 'REC-fixture']] as $call) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $method = array_shift($call);
        try { $controller->$method(...$call); throw new RuntimeException('Missing authentication accepted: ' . $method); }
        catch (BackupManager\Provider\JsonExit $response) {
            if ($response->status !== 401) { throw new RuntimeException('Unexpected authentication response: ' . $method); }
        }
    }
    if (method_exists($controller, 'approvalAction')) { throw new RuntimeException('Legacy approval capability remains'); }
    $fingerprint = new ReflectionMethod($controller, 'fingerprint');
    $key = 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('x', 32));
    if ($fingerprint->invoke($controller, $key) === null || $fingerprint->invoke($controller, $key . "\nssh-ed25519 AAAA") !== null) {
        throw new RuntimeException('SSH key validation failed');
    }
    $routes = file_get_contents($root . '/provider/public/index.php');
    if (str_contains($routes, '/approval/')) { throw new RuntimeException('State-changing approval route remains'); }
    echo "Provider authentication and public-key boundary tests passed.\n";
}
