<?php
declare(strict_types=1);
namespace OCP { interface IConfig {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\AppFramework { class Controller {} }
namespace OCP\AppFramework\Http {
    class JSONResponse { public function __construct(public array $data, public int $status) {} }
}
namespace {
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
        public function newClient() { return $this; }
        public function get($url, $options) { return $this; }
        public function getBody() { return json_encode($this->result); }
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
    echo "Settings request status regression tests passed.\n";
}
