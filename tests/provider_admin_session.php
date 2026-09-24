<?php
declare(strict_types=1);
namespace BackupManager\Provider {
    function is_dir($path) { return \is_dir($path === '/var/lib/backupmanager-provider-api/limits' ? getenv('BM_ADMIN_SESSION_ROOT').'/limits' : $path); }
    function fopen($path, $mode) { return \fopen(str_starts_with($path, '/var/lib/backupmanager-provider-api/limits/') ? getenv('BM_ADMIN_SESSION_ROOT').'/limits/'.basename($path) : $path, $mode); }
    function header($header, ...$args) { $GLOBALS['fixture_headers'][]=$header; }
}
namespace {
    $root=getenv('BM_ADMIN_SESSION_ROOT');
    if (!preg_match('#^/tmp/bm-admin-session-[A-Za-z0-9_-]+$#D',$root)) { exit(2); }
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    require dirname(__DIR__).'/provider/src/Config.php';
    require dirname(__DIR__).'/provider/src/Auth.php';
    require dirname(__DIR__).'/provider/src/AdminText.php';
    $_GET['lang']=$input['lang'] ?? 'en';
    require dirname(__DIR__).'/provider/src/Response.php';
    ini_set('session.save_path',$root.'/sessions');
    ini_set('session.use_cookies','0');
    if (!empty($input['session'])) { session_id($input['session']); }
    $_SERVER['HTTPS']=$input['https'] ?? 'on';
    $_SERVER['REQUEST_METHOD']=empty($input['post']) ? 'GET' : 'POST';
    $_SERVER['REMOTE_ADDR']='127.0.0.1';
    $_POST=$input['post'] ?? [];
    register_shutdown_function(static function() use ($root) {
        file_put_contents($root.'/response.json',json_encode(['status'=>http_response_code() ?: 200,'headers'=>$GLOBALS['fixture_headers'] ?? [],'session'=>session_id(),'csrf'=>$_SESSION['csrf'] ?? '']));
    });
    BackupManager\Provider\Auth::requireAdmin(new BackupManager\Provider\Config($root.'/config.php'));
    echo 'AUTHENTICATED';
}
