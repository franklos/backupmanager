<?php
declare(strict_types=1);
namespace OCP { interface IConfig {} interface IRequest {} interface IUserSession {} interface IGroupManager {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\AppFramework { class Controller { public function __construct($app, protected $request) {} } }
namespace OCP\AppFramework\Http { class JSONResponse { public function __construct(public array $data, public int $status = 200) {} } }
namespace OCA\BackupStatus\Controller {
    function is_readable($path) { return $path === '/etc/backupmanager/management-token' ? \is_readable(getenv('BM_JOURNEY_ROOT').'/management-token') : \is_readable($path); }
    function file_get_contents($path, ...$options) { return \file_get_contents($path === '/etc/backupmanager/management-token' ? getenv('BM_JOURNEY_ROOT').'/management-token' : $path, ...$options); }
}
namespace {
    $root = getenv('BM_JOURNEY_ROOT');
    if (!preg_match('#^/tmp/bm-integration-[A-Za-z0-9_-]+$#D', $root)) { exit(2); }
    ini_set('error_log', $root . '/application.log');
    require dirname(__DIR__) . '/app/backupstatus/lib/Service/ErrorMessages.php';
    require dirname(__DIR__) . '/app/backupstatus/lib/Service/RuntimeService.php';
    require dirname(__DIR__) . '/app/backupstatus/lib/Controller/SettingsController.php';
    $config = new class($root) implements OCP\IConfig {
        public array $values;
        public function __construct(private string $root) { $this->values = json_decode(file_get_contents($root . '/appconfig.json'), true); }
        public function getAppValue($app, $key, $default) { return $this->values[$key] ?? $default; }
        public function setAppValue($app, $key, $value) { $this->values[$key] = $value; $this->save(); }
        public function deleteAppValue($app, $key) { unset($this->values[$key]); $this->save(); }
        private function save() { file_put_contents($this->root . '/appconfig.json', json_encode($this->values)); }
    };
    $http = new class($root) implements OCP\Http\Client\IClientService {
        public function __construct(private string $root) {}
        public function request($method, $url, $options) { return $this->send($method, $url, $options); }
        public function newClient() { return $this; }
        public function post($url, $options) { return $this->send('post', $url, $options); }
        public function get($url, $options) { return $this->send('get', $url, $options); }
        private function send($method, $url, $options) {
            if (parse_url($url, PHP_URL_HOST) !== 'provider.example.test' || parse_url($url, PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('Fixture refused external network'); }
            // Local TLS fixture stands in for DNS/Nextcloud's HTTP service only.
            // Verify its private CA and localhost certificate; never disable TLS checks.
            $port=(int)getenv('BM_JOURNEY_HTTPS_PORT');
            if ($port < 1024 || $port > 65535) { throw new RuntimeException('Invalid fixture HTTPS port'); }
            $headers=[];
            foreach ($options['headers'] as $name=>$value) { $headers[]=$name.': '.$value; }
            $context=stream_context_create([
                'http'=>['method'=>strtoupper($method),'header'=>implode("\r\n",$headers),
                    'content'=>$options['body'] ?? '', 'ignore_errors'=>true, 'follow_location'=>0, 'timeout'=>30],
                'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'localhost','cafile'=>$this->root.'/https.crt'],
            ]);
            $body=file_get_contents('https://127.0.0.1:'.$port.parse_url($url,PHP_URL_PATH),false,$context);
            if ($body === false) { throw new RuntimeException('Fixture HTTPS transport failed'); }
            preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0] ?? '',$status);
            $code=(int)($status[1] ?? 0);
            $type = '';
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Content-Type:') === 0) { $type = trim(substr($header, 13)); }
            }
            return new class($body, $code, $type) {
                public function __construct(private string $body, private int $status, private string $type) {}
                public function getHeader($name) { return $this->type; }
                public function getBody() { return $this->body; }
                public function getStatusCode() { return $this->status; }
            };
        }
    };
    $controller = (new ReflectionClass(OCA\BackupStatus\Controller\SettingsController::class))->newInstanceWithoutConstructor();
    foreach (['config'=>$config,'clientService'=>$http,'runtime'=>new OCA\BackupStatus\Service\RuntimeService()] as $name=>$value) {
        (new ReflectionProperty($controller,$name))->setValue($controller,$value);
    }
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $routes=require dirname(__DIR__).'/app/backupstatus/appinfo/routes.php';
    $route=array_values(array_filter($routes['routes'],static fn($r)=>$r['url']==='/settings/'.$input['route'] && $r['verb']==='POST'))[0] ?? null;
    if (!$route || !in_array($input['route'],['request-recovery','recovery-status','provider-status','refresh-provider','provider-admin-requests','provider-admin-action'],true)) { exit(2); }
    if (str_starts_with($route['name'], 'providerAdmin#')) {
        require dirname(__DIR__) . '/app/backupstatus/lib/Controller/ProviderAdminController.php';
        $request=new class implements OCP\IRequest { public function passesCSRFCheck() { return true; } };
        $users=new class implements OCP\IUserSession { public function getUser() { return new class { public function getUID() { return 'fixture-operator'; } }; } };
        $groups=new class implements OCP\IGroupManager { public function isAdmin($id) { return true; } };
        $controller=new OCA\BackupStatus\Controller\ProviderAdminController($request,$config,$http,$users,$groups);
    }
    $method=explode('#',$route['name'])[1];
    echo json_encode($controller->$method(...($input['values'] ?? []))->data,JSON_THROW_ON_ERROR);
}
