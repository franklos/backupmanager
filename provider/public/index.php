<?php

declare(strict_types=1);

use BackupManager\Provider\Config;
use BackupManager\Provider\Database;
use BackupManager\Provider\Response;
use BackupManager\Provider\Router;
use BackupManager\Provider\Controller\RequestController;

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

try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on') {
        Response::json(['success' => false, 'error' => 'HTTPS is required'], 403);
    }
    $config = new Config(dirname(__DIR__) . '/config/config.php');
    $database = new Database($config);

    header('Cache-Control: no-store');
    $requestController = new RequestController($database, $config);
    $router = new Router();

    $router->add(
        'POST',
        '#^/api/v1/requests/?$#',
        [$requestController, 'create']
    );

    $router->add(
        'GET',
        '#^/api/v1/requests/([A-Za-z0-9-]+)/?$#',
        [$requestController, 'status']
    );

    $router->add(
        'POST',
        '#^/api/v1/clients/(BM-[0-9]{6})/deletion-requests/?$#',
        [$requestController, 'requestDeletion']
    );

    $router->add(
        'GET',
        '#^/api/v1/clients/(BM-[0-9]{6})/status/?$#',
        [$requestController, 'clientStatus']
    );

    $router->add(
        'GET',
        '#^/api/v1/management/clients/?$#',
        [$requestController, 'managementClients']
    );

    $router->add(
        'POST',
        '#^/api/v1/management/clients/(BM-[0-9]{6})/(pause|resume)/?$#',
        [$requestController, 'managementClientState']
    );

    $router->add(
        'DELETE',
        '#^/api/v1/management/clients/(BM-[0-9]{6})/?$#',
        [$requestController, 'managementDeleteClient']
    );

    $router->add(
        'POST',
        '#^/api/v1/management/clients/(BM-[0-9]{6})/remove/?$#',
        [$requestController, 'managementRemoveClient']
    );


    $router->add(
        'POST',
        '#^/api/v1/recovery-requests/?$#',
        [$requestController, 'createRecovery']
    );

    $router->add(
        'GET',
        '#^/api/v1/recovery-requests/([A-Za-z0-9-]+)/?$#',
        [$requestController, 'recoveryStatus']
    );

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (!is_string($path)) {
        Response::json([
            'success' => false,
            'error' => 'Invalid request path',
        ], 400);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array(rtrim($path, '/'), ['/api/v1/requests', '/api/v1/recovery-requests'], true)) {
        \BackupManager\Provider\Auth::rateLimit('enrollment');
    }
    $router->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $path
    );
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'error' => 'Provider unavailable',
    ], 500);
}
