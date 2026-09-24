<?php
declare(strict_types=1);
namespace BackupManager\Provider\Controller {
    function file_get_contents($path, ...$args) {
        return $path === 'php://input' ? ($GLOBALS['input']['body'] ?? '') : \file_get_contents($path, ...$args);
    }
}
namespace BackupManager\Provider {
    function is_dir($path) { return \is_dir($path === '/var/lib/backupmanager-provider-api/limits' ? getenv('BM_JOURNEY_ROOT') . '/limits' : $path); }
    function fopen($path, $mode) {
        if (str_starts_with($path, '/var/lib/backupmanager-provider-api/limits/')) {
            $path = getenv('BM_JOURNEY_ROOT') . '/limits/' . basename($path);
        }
        return \fopen($path, $mode);
    }
}
namespace {
    require __DIR__ . '/journey_boundary.php';
    $GLOBALS['input'] = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $input = $GLOBALS['input'];
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_URI'] = $input['path'];
    $_SERVER['REQUEST_METHOD'] = strtoupper($input['method']);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_AUTHORIZATION'] = $input['token'] ?? '';
    // Preserve actual HTTP status across the isolated process boundary.
    register_shutdown_function(static function (): void {
        file_put_contents(getenv('BM_JOURNEY_ROOT') . '/http-status', (string)(http_response_code() ?: 200));
    });
    date_default_timezone_set('Pacific/Honolulu');
    $root=getenv('BM_JOURNEY_ROOT');
    if (!preg_match('#^/tmp/bm-integration-[A-Za-z0-9_-]+$#D', $root)) { exit(2); }
    require $root . '/installed/opt/backupmanager-provider/public/index.php';
}
