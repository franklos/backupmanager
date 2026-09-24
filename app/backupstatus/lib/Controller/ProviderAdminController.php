<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Controller;
use OCA\BackupStatus\Service\ErrorMessages;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\{IConfig, IGroupManager, IRequest, IUserSession};

/** Nextcloud administrator/CSRF middleware plus explicit server-side guards. */
final class ProviderAdminController extends Controller {
    public function __construct(IRequest $request, private IConfig $config, private IClientService $clients,
        private IUserSession $users, private IGroupManager $groups) { parent::__construct('backupstatus', $request); }
    private function deny(): ?JSONResponse {
        $user = $this->users->getUser();
        if (!$user || !$this->groups->isAdmin($user->getUID()) || !$this->request->passesCSRFCheck()) {
            return $this->failure('provider_forbidden', 403);
        }
        return null;
    }
    private function failure(string $code, int $status = 400): JSONResponse {
        if (!isset(ErrorMessages::MESSAGES[$code])) { $code = 'provider_action_failed'; }
        error_log('Backup Manager provider administration failed: ' . $code);
        return new JSONResponse(['success' => false, 'error_code' => $code,
            'error_message' => ErrorMessages::message(['error_code' => $code])], $status);
    }
    private function token(): string {
        $file = '/etc/backupmanager/management-token';
        return is_readable($file) ? trim((string)file_get_contents($file)) : '';
    }
    private function api(string $method, string $path, array $body = []): array {
        $token = $this->token();
        if ($token === '') { throw new \RuntimeException('provider_management_unconfigured'); }
        $url = rtrim($this->config->getAppValue('backupstatus', 'provider_url', ''), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null
            || !in_array(parse_url($url, PHP_URL_PATH), ['', '/', null], true)) {
            throw new \RuntimeException('provider_url_invalid');
        }
        try {
            $options = ['headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token], 'timeout' => 30, 'http_errors' => false, 'allow_redirects' => false];
            if ($method === 'POST') { $options['body'] = json_encode($body, JSON_THROW_ON_ERROR); }
            $response = $this->clients->newClient()->request($method, $url . '/api/v1/management/requests' . $path, $options);
        } catch (\Throwable $error) {
            error_log('Backup Manager provider administration transport failed: ' . get_class($error));
            throw new \RuntimeException('provider_unavailable');
        }
        $raw = (string)$response->getBody();
        $data = strlen($raw) <= 2097152 ? json_decode($raw, true) : null;
        if (strtolower(trim(explode(';', $response->getHeader('Content-Type'))[0])) !== 'application/json'
            || !is_array($data) || !is_bool($data['success'] ?? null)) {
            error_log('Backup Manager provider administration invalid response: HTTP=' . $response->getStatusCode() . '; bytes=' . strlen($raw));
            throw new \RuntimeException('provider_invalid_response');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || $data['success'] !== true) {
            $code = $data['error_code'] ?? 'provider_action_failed';
            throw new \RuntimeException(is_string($code) && isset(ErrorMessages::MESSAGES[$code]) ? $code : 'provider_action_failed');
        }
        return $data;
    }
    public function requests(): JSONResponse {
        if ($denied = $this->deny()) { return $denied; }
        if ($this->token() === '') { return new JSONResponse(['success' => true, 'configured' => false, 'requests' => []]); }
        try {
            $data = $this->api('GET', '');
            if (!is_array($data['requests'] ?? null) || !array_is_list($data['requests']) || count($data['requests']) > 1000
                || !is_bool($data['storage_configured'] ?? null)) { throw new \RuntimeException('provider_invalid_response'); }
            $rows = [];
            foreach ($data['requests'] as $row) {
                if (!is_array($row)) { throw new \RuntimeException('provider_invalid_response'); }
                $safe = [];
                foreach (['request_id', 'type', 'client_id', 'source_id', 'current_source_id', 'source_url', 'contact', 'requested_at', 'expires_at', 'status', 'write_fingerprint', 'read_fingerprint'] as $field) {
                    if (!is_string($row[$field] ?? null) || strlen($row[$field]) > 4096) { throw new \RuntimeException('provider_invalid_response'); }
                    $safe[$field] = $row[$field];
                }
                $prefix = ['enrollment' => 'REQ', 'recovery' => 'REC'][$safe['type']] ?? '';
                if ($prefix === '' || !preg_match('/^' . $prefix . '-[0-9]{8}-[A-F0-9]{6}$/D', $safe['request_id'])
                    || !in_array($safe['status'], ['pending', 'approved', 'rejected', 'expired'], true)
                    || ($safe['type'] === 'recovery' && !preg_match('/^BM-[0-9]{6}$/D', $safe['client_id']))) {
                    throw new \RuntimeException('provider_invalid_response');
                }
                $expiry = strtotime($safe['expires_at'] . ' UTC');
                if ($safe['status'] === 'pending' && (!$expiry || $expiry <= time())) { $safe['status'] = 'expired'; }
                // Derive actionability server-side; never trust a provider-supplied flag.
                $safe['can_reject'] = $safe['status'] === 'pending';
                $safe['can_approve'] = $safe['can_reject'] && $data['storage_configured']
                    && str_starts_with($safe['write_fingerprint'], 'SHA256:') && str_starts_with($safe['read_fingerprint'], 'SHA256:')
                    && $safe['write_fingerprint'] !== $safe['read_fingerprint'];
                $rows[] = $safe;
            }
            return new JSONResponse(['success' => true, 'configured' => true, 'requests' => $rows,
                'storage_configured' => $data['storage_configured']]);
        } catch (\Throwable $error) { return $this->failure($error->getMessage()); }
    }
    public function action(string $requestType = '', string $requestId = '', string $action = '', string $confirm = ''): JSONResponse {
        if ($denied = $this->deny()) { return $denied; }
        $prefix = ['enrollment' => 'REQ', 'recovery' => 'REC'][$requestType] ?? '';
        if ($confirm !== '1' || $prefix === '' || !preg_match('/^' . $prefix . '-[0-9]{8}-[A-F0-9]{6}$/D', $requestId)
            || !in_array($action, ['approve', 'reject'], true)) { return $this->failure('provider_invalid_request'); }
        try {
            $data = $this->api('POST', '/' . $requestType . '/' . $requestId . '/' . $action, ['actor' => $this->users->getUser()->getUID()]);
            if (($data['request_id'] ?? null) !== $requestId || ($data['type'] ?? null) !== $requestType
                || ($data['status'] ?? null) !== ($action === 'approve' ? 'approved' : 'rejected')) { throw new \RuntimeException('provider_invalid_response'); }
            return new JSONResponse(['success' => true, 'request_id' => $requestId, 'status' => $data['status']]);
        } catch (\Throwable $error) { return $this->failure($error->getMessage()); }
    }
}
