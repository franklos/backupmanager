<?php
declare(strict_types=1);
namespace BackupManager\Provider\Controller {
    function file_get_contents($path, ...$args) {
        return $path === 'php://input' ? json_encode($GLOBALS['fixtureInput']['body'] ?? []) : \file_get_contents($path, ...$args);
    }
}
namespace {
require __DIR__ . '/provider_fixture_bootstrap.php';
require dirname(__DIR__) . '/provider/src/Response.php';
require dirname(__DIR__) . '/provider/src/StorageEndpoint.php';
require dirname(__DIR__) . '/provider/src/Controller/RequestController.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!in_array($input['method'] ?? '', ['status', 'recoveryStatus', 'clientConnection', 'create', 'createRecovery'], true)) { exit(2); }
$GLOBALS['fixtureInput'] = $input;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . ($input['token'] ?? '');
$config = new BackupManager\Provider\Config();
$controller = new BackupManager\Provider\Controller\RequestController(new BackupManager\Provider\Database($config), $config);
$controller->{$input['method']}(...(isset($input['id']) ? [$input['id']] : []));
}
