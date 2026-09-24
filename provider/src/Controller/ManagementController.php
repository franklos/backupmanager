<?php
declare(strict_types=1);
namespace BackupManager\Provider\Controller;
use BackupManager\Provider\{Auth, Config, Database, RequestAdministration, Response};
final class ManagementController {
    public function __construct(private Database $database, private Config $config) {}
    public function requests(): never {
        Auth::requireManagement($this->config);
        Response::json((new RequestAdministration($this->database, $this->config))->inventory());
    }
    public function action(string $type, string $id, string $action): never {
        Auth::requireManagement($this->config);
        $raw = file_get_contents('php://input');
        $body = strlen($raw) <= 4096 ? json_decode($raw, true) : null;
        if (!is_array($body) || !is_string($body['actor'] ?? null)) { Response::json(['success' => false, 'error_code' => 'invalid_request'], 400); }
        $result = (new RequestAdministration($this->database, $this->config))->act($type, $id, $action, 'nextcloud:' . $body['actor']);
        Response::json($result, $result['success'] ? 200 : 409);
    }
}
