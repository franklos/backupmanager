<?php
declare(strict_types=1);
namespace OCP { interface IConfig {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\AppFramework { class Controller {} }
namespace OCP\AppFramework\Http {
    class JSONResponse { public function __construct(public array $data, public int $status) {} }
}
namespace OCA\BackupStatus\Service {
    class RuntimeService {
        public array $calls = [];
        public bool $success = true;
        public function helper(string $name, array $input = []): array {
            $this->calls[] = [$name, $input];
            return ['success' => $this->success, 'error' => 'fixture failure', 'source_id' => 'ncdev-legacy', 'public_key' => 'write-fixture', 'restore_public_key' => 'read-fixture'];
        }
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/backupstatus/lib/Service/ErrorMessages.php';
require dirname(__DIR__) . '/app/backupstatus/lib/Controller/SettingsController.php';
    $controller = (new ReflectionClass(OCA\BackupStatus\Controller\SettingsController::class))->newInstanceWithoutConstructor();
    $config = new class implements OCP\IConfig {
        public array $values = ['provider_client_id' => 'BM-000001'];
        public function getAppValue($app, $key, $default) { return $this->values[$key] ?? $default; }
        public function setAppValue($app, $key, $value) { $this->values[$key] = $value; }
        public function deleteAppValue($app, $key) { unset($this->values[$key]); }
    };
    (new ReflectionProperty($controller, 'config'))->setValue($controller, $config);
    foreach (['provider' => 'providerStatus', 'recovery' => 'recoveryStatus'] as $prefix => $method) {
        $config->values[$prefix . '_request_status'] = 'pending';
        $result = $controller->$method();
        if ($result->data['status'] !== 'stale' || $config->values[$prefix . '_request_status'] !== 'stale') {
            throw new RuntimeException('Orphan pending request must transition to stale');
        }
        if ($result->data['clientId'] !== 'BM-000001') { throw new RuntimeException('Missing client identity'); }
        $config->values[$prefix . '_request_status'] = 'approved';
        if ($controller->$method()->data['status'] !== 'approved') { throw new RuntimeException('Approval changed'); }
    }
    $service = new class implements \OCP\Http\Client\IClientService {
        public array $result = [];
        public array $posts = [];
        public ?string $rawBody = null;
        public string $contentType = 'application/json';
        public function getHeader($name) { return $this->contentType; }
        public function request($method, $url, $options) {
            if ($method !== strtoupper($method)) { throw new RuntimeException('HTTP method must be uppercase'); }
            return $method === 'POST' ? $this->post($url, $options) : $this->get($url, $options);
        }
        public $onGet = null;
        public function post($url, $options) { $this->posts[] = [$url, json_decode($options['body'], true)]; return $this; }
        public function newClient() { return $this; }
        public function get($url, $options) { $this->result[str_contains($url, '/recovery-requests/') ? 'recovery_request_id' : 'request_id'] = basename($url); if ($this->onGet) { ($this->onGet)(); } return $this; }
        public function getBody() { return $this->rawBody ?? json_encode($this->result); }
        public function getStatusCode() { return 200; }
    };
    (new ReflectionProperty($controller, 'clientService'))->setValue($controller, $service);
    $config->values['provider_url'] = 'https://provider.example';
    foreach (['provider' => 'providerStatus', 'recovery' => 'recoveryStatus'] as $prefix => $method) {
        $config->values[$prefix . '_request_id'] = 'request-fixture';
        $config->values[$prefix . '_request_status'] = 'pending';
        $service->result = ['success' => true, 'status' => 'pending'];
        if ($controller->$method()->data['status'] !== 'pending'
            || !isset($config->values[$prefix . '_request_id'])) {
            throw new RuntimeException('Real pending request was lost');
        }
        $service->result['status'] = 'approved';
        if ($controller->$method()->data['success']
            || $config->values[$prefix . '_request_status'] !== 'pending'
            || !isset($config->values[$prefix . '_request_id'])) {
            throw new RuntimeException('Incomplete approval was consumed');
        }
        $service->result['status'] = 'rejected';
        if ($controller->$method()->data['status'] !== 'rejected'
            || isset($config->values[$prefix . '_request_id'])) {
            throw new RuntimeException('Terminal request did not transition');
        }
    }
    $runtime = new OCA\BackupStatus\Service\RuntimeService();
    (new ReflectionProperty($controller, 'runtime'))->setValue($controller, $runtime);
    $service->result = ['success' => true, 'status' => 'approved', 'connection' => [
        'client_id' => 'BM-000007', 'host' => 'backup.ncdev.local', 'port' => 22,
        'user' => 'backupstore', 'path' => '/',
    ]];
    foreach ([false, true] as $success) {
        $runtime->success = $success;
        $runtime->calls = [];
        $config->values['provider_request_id'] = 'obsolete-enrollment';
        $config->values['recovery_request_id'] = 'recovery-fixture';
        $config->values['recovery_request_token'] = 'fixture-token';
        $result = $controller->recoveryStatus();
        if ($result->data['success'] !== $success || count($runtime->calls) !== 1
            || $runtime->calls[0][0] !== 'apply-provider-config'
            || $runtime->calls[0][1]['activate_recovery'] !== true) {
            throw new RuntimeException('Recovery must validate and activate through one helper');
        }
        if ($success && isset($config->values['provider_request_id'])) { throw new RuntimeException('Recovery left obsolete enrollment paired with the new token'); }
        if (isset($config->values['recovery_request_id']) === $success) {
            throw new RuntimeException('Failed recovery application must retain pending request');
        }
    }
    if ($config->values['credential_mode'] !== 'managed' || $config->values['destination_type'] !== 'ssh'
        || end($runtime->calls)[1]['host'] !== 'backup.ncdev.local' || !$result->data['applied']) {
        throw new RuntimeException('Recovery lost the managed canonical assignment');
    }
    // Already-approved refresh never replays recovery and changes app mode only on success.
    $config->values['provider_client_id'] = 'BM-000007';
    $service->result['connection']['activate_recovery'] = true; // Must not cross the refresh boundary.
    foreach ([false, true] as $success) {
        $config->values['credential_mode'] = 'manual';
        $runtime->success = $success;
        $result = $controller->refreshProvider();
        $call = end($runtime->calls);
        if ($result->data['success'] !== $success || $call[0] !== 'apply-provider-config'
            || $call[1]['activate_recovery'] !== false || $call[1]['select_managed'] !== true
            || $call[1]['host'] !== 'backup.ncdev.local'
            || $config->values['credential_mode'] !== ($success ? 'managed' : 'manual')) {
            throw new RuntimeException('Assignment refresh lost mode, canonical host, or key preservation');
        }
    }
    $service->result['connection']['client_id'] = 'BM-000008';
    $count = count($runtime->calls);
    if ($controller->refreshProvider()->data['success'] || count($runtime->calls) !== $count) {
        throw new RuntimeException('Refresh accepted a different permanent client');
    }
    $service->result['connection']['client_id'] = 'BM-000007';
    foreach (['expired', 'pending', 'stale'] as $oldStatus) {
        $config->values['recovery_request_status'] = $oldStatus;
        $config->values['recovery_request_id'] = 'old-request';
        $service->result = ['success'=>true,'status'=>'pending','recovery_request_id'=>'REC-fresh-request','request_token'=>'new-token'];
        $result=$controller->requestRecovery('BM-000007','https://provider.example','1');
        if (!$result->data['success'] || $config->values['recovery_request_id'] !== 'REC-fresh-request'
            || $config->values['provider_client_id'] !== 'BM-000007'
            || end($service->posts)[1]['restore_public_key'] !== 'read-fixture') {
            throw new RuntimeException('Recover access did not create a fresh two-key request for the same client');
        }
    }
    $config->values['recovery_request_id']='old-poll';
    $config->values['recovery_request_token']='old-token';
    $service->result=['success'=>true,'status'=>'expired'];
    $service->onGet=function() use ($config) {
        $config->values['recovery_request_id']='new-poll';
        $config->values['recovery_request_token']='new-token';
        $config->values['recovery_request_status']='pending';
    };
    $result=$controller->recoveryStatus();
    if ($config->values['recovery_request_id'] !== 'new-poll' || $result->data['status'] !== 'pending') {
        throw new RuntimeException('A stale poll discarded the fresh recovery request');
    }
    $service->onGet = null;
    foreach ([['text/html', '<html>login</html>'], ['', '<?php echo "source";'],
              ['application/json', '{"success":true}'], ['application/json', '{"success":"yes"}'],
              ['application/json', 'not JSON']] as [$type, $body]) {
        $service->contentType = $type; $service->rawBody = $body;
        $config->values['recovery_request_id'] = 'REC-20260921-550904';
        $config->values['provider_request_id'] = 'old-enrollment';
        $config->values['recovery_request_token'] = 'old-token';
        $result = $controller->requestRecovery('BM-000007', 'https://provider.example', '1');
        if ($result->data['success'] || $result->data['error_code'] !== 'provider_invalid_response'
            || isset($config->values['recovery_request_id']) || isset($config->values['recovery_request_token'])
            || isset($config->values['provider_request_id']) || $config->values['provider_client_id'] !== 'BM-000007') {
            throw new RuntimeException('False HTTP 200 retained stale recovery or reported success');
        }
        $poll = $controller->recoveryStatus();
        if ($poll->data['success'] || $poll->data['error_code'] !== 'provider_invalid_response'
            || !$poll->data['error_message']) { throw new RuntimeException('Recovery failure did not survive reload/poll'); }
    }
    $service->rawBody = null; $service->contentType = 'application/json; charset=utf-8';
    $service->result = ['success'=>true,'status'=>'pending','recovery_request_id'=>'REC-valid-retry','request_token'=>'new-token'];
    if (!$controller->requestRecovery('BM-000007', 'https://provider.example', '1')->data['success']
        || isset($config->values['recovery_request_error'])) { throw new RuntimeException('Valid recovery retry failed'); }
    echo "Settings request status regression tests passed.\n";
}
