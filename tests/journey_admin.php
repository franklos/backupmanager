<?php
declare(strict_types=1);
namespace BackupManager\Provider {
    function is_dir($path) { return \is_dir($path === '/var/lib/backupmanager-provider-api/limits' ? getenv('BM_JOURNEY_ROOT').'/limits' : $path); }
    function fopen($path,$mode) { return \fopen(str_starts_with($path,'/var/lib/backupmanager-provider-api/limits/') ? getenv('BM_JOURNEY_ROOT').'/limits/'.basename($path) : $path,$mode); }
    function header($header,...$args) { $GLOBALS['fixture_headers'][]=$header; }
}
namespace {
    require __DIR__.'/journey_boundary.php';
    $root=getenv('BM_JOURNEY_ROOT');
    if (!preg_match('#^/tmp/bm-integration-[A-Za-z0-9_-]+$#D',$root)) { exit(2); }
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    ini_set('session.save_path',$root.'/admin-sessions');ini_set('session.use_cookies','0');
    if (!empty($input['session'])) { session_id($input['session']); }
    $_SERVER['HTTPS']='on';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    $_SERVER['REQUEST_METHOD']=empty($input['post'])?'GET':'POST';$_POST=$input['post'] ?? [];
    register_shutdown_function(static function() use($root):void {
        file_put_contents($root.'/admin-http.json',json_encode(['status'=>http_response_code() ?: 200,'headers'=>$GLOBALS['fixture_headers'] ?? [],'session'=>session_id(),'csrf'=>$_SESSION['csrf'] ?? '']));
    });
    require $root.'/installed/opt/backupmanager-provider/public/admin/index.php';
}
