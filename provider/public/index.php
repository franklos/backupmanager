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
    $config = new Config(dirname(__DIR__) . '/config/config.php');
    $database = new Database($config);

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
        '#^/api/v1/requests/([A-Za-z0-9-]+)/?$#',
        [$requestController, 'status']
    );

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (!is_string($path)) {
        Response::json([
            'success' => false,
            'error' => 'Invalid request path',
        ], 400);
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
