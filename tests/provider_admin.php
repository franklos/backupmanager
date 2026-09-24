<?php
declare(strict_types=1);
namespace OCP { interface IConfig {} interface IRequest {} interface IUserSession {} interface IGroupManager {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\AppFramework { class Controller { public function __construct($app, protected $request) {} } }
namespace OCP\AppFramework\Http { class JSONResponse { public function __construct(public array $data, public int $status = 200) {} } }
namespace OCA\BackupStatus\Controller {
    function is_readable($path) { return $path === '/etc/backupmanager/management-token' && $GLOBALS['token'] !== ''; }
    function file_get_contents($path) { if ($path !== '/etc/backupmanager/management-token') { throw new \RuntimeException('Unexpected secret path'); } return $GLOBALS['token']; }
}
namespace {
    require dirname(__DIR__) . '/app/backupstatus/lib/Service/ErrorMessages.php';
    require dirname(__DIR__) . '/app/backupstatus/lib/Controller/ProviderAdminController.php';
    function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
    $GLOBALS['token'] = 'private-fixture-management-token';
    $request = new class implements OCP\IRequest { public bool $csrf = true; public function passesCSRFCheck() { return $this->csrf; } };
    $config = new class implements OCP\IConfig { public function getAppValue(...$args) { return 'https://provider.example.test'; } };
    $users = new class implements OCP\IUserSession { public function getUser() { return new class { public function getUID() { return 'fixture-admin'; } }; } };
    $groups = new class implements OCP\IGroupManager { public bool $admin = true; public function isAdmin($id) { return $this->admin; } };
    $http = new class implements OCP\Http\Client\IClientService {
        public array $data = []; public string $type='application/json'; public ?string $raw=null; public array $calls=[];
        public function newClient() { return $this; }
        public function request($method,$url,$options) { $this->calls[]=[$method,$url,$options]; return $this; }
        public function getHeader($name) { return $this->type; }
        public function getBody() { return $this->raw ?? json_encode($this->data); }
        public function getStatusCode() { return 200; }
    };
    $controller = new OCA\BackupStatus\Controller\ProviderAdminController($request,$config,$http,$users,$groups);
    $base=['source_id'=>'fixture-source','current_source_id'=>'existing-fixture-source','source_url'=>'https://source.example.test','contact'=>'fixture@example.test','requested_at'=>'2026-01-01 00:00:00',
        'expires_at'=>'2099-01-01 00:00:00','status'=>'pending','write_fingerprint'=>'SHA256:write','read_fingerprint'=>'SHA256:read',
        'can_approve'=>true,'can_reject'=>true,'management_token'=>'MUST NOT LEAK','private_key'=>'MUST NOT LEAK'];
    $rows=[];
    foreach (['enrollment'=>'REQ','recovery'=>'REC'] as $type=>$prefix) {
        $rows[]=$base+['request_id'=>$prefix.'-20260923-AAAAAA','type'=>$type,'client_id'=>$type==='recovery'?'BM-000007':''];
        $expired=end($rows); $expired['request_id']=$prefix.'-20260921-BBBBBB'; $expired['expires_at']='2026-01-01 00:00:00'; $rows[]=$expired;
    }
    $http->data=['success'=>true,'requests'=>$rows,'storage_configured'=>true,'management_token'=>'MUST NOT LEAK'];
    $result=$controller->requests();
    check($result->data['configured'] && count($result->data['requests'])===4,'Missing requests');
    foreach ($result->data['requests'] as $row) {
        check($row['can_approve'] === ($row['status']==='pending'),'Expiry remained actionable');
        check($row['write_fingerprint'] !== $row['read_fingerprint'],'Fingerprints lost');
    }
    check(!str_contains(json_encode($result->data),'MUST NOT LEAK') && !str_contains(json_encode($result->data),$GLOBALS['token']),'Browser credential disclosure');
    check($http->calls[0][2]['headers']['Authorization']==='Bearer '.$GLOBALS['token'],'Missing server-side management authentication');
    $groups->admin=false; $calls=count($http->calls);
    check($controller->requests()->status===403,'Non-admin inventory accepted');
    check($controller->action('recovery','REC-20260923-AAAAAA','approve','1')->status===403,'Non-admin approval accepted');
    $groups->admin=true; $request->csrf=false;
    check($controller->action('recovery','REC-20260923-AAAAAA','approve','1')->status===403,'Missing CSRF accepted');
    check(count($http->calls)===$calls,'Unauthorized request reached provider');
    $request->csrf=true;
    check(!$controller->action('recovery','REQ-20260923-AAAAAA','approve','1')->data['success'],'Cross-type request accepted');
    check(!$controller->action('recovery','REC-20260923-AAAAAA','approve','0')->data['success'],'Unconfirmed action accepted');
    foreach (['text/html','text/plain'] as $type) {
        $http->type=$type; $http->raw='<html>HTTP 200 generic page</html>';
        check($controller->requests()->data['error_code']==='provider_invalid_response','False-200 inventory accepted');
        check($controller->action('recovery','REC-20260923-AAAAAA','approve','1')->data['error_code']==='provider_invalid_response','False-200 approval accepted');
    }
    $http->type='application/json'; $http->raw='{"success":true}';
    check($controller->requests()->data['error_code']==='provider_invalid_response','Malformed API response accepted');
    $http->raw=null;
    $http->data=['success'=>true,'request_id'=>'REC-20260923-AAAAAA','type'=>'recovery','status'=>'approved','private_key'=>'MUST NOT LEAK'];
    $result=$controller->action('recovery','REC-20260923-AAAAAA','approve','1');
    check($result->data['success'] && !str_contains(json_encode($result->data),'MUST NOT LEAK'),'Safe action response failed');
    check(json_decode(end($http->calls)[2]['body'],true)['actor']==='fixture-admin','Audit actor is not authenticated administrator');
    $GLOBALS['token']='';
    check($controller->requests()->data['configured']===false,'Missing token not reported');
    $routes=require dirname(__DIR__).'/app/backupstatus/appinfo/routes.php';
    foreach ($routes['routes'] as $route) { if (str_starts_with($route['name'],'providerAdmin#')) { check($route['verb']==='POST','Management route lost CSRF-protected method'); } }
    echo "Nextcloud provider administration authorization, CSRF, expiry, response and credential-boundary tests passed.\n";
}
