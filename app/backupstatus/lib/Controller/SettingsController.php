<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Controller;

use OCA\BackupStatus\Service\RuntimeService;
use OCA\BackupStatus\Service\Removal\ProviderRemovalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\ServerVersion;
use Throwable;

/** All actions retain Nextcloud's default administrator and CSRF requirements. */
final class SettingsController extends Controller {
    public function __construct(IRequest $request, private IConfig $config,
        private IClientService $clientService, private IUserSession $userSession,
        private IMailer $mailer, private ServerVersion $serverVersion, private RuntimeService $runtime) {
        parent::__construct('backupstatus', $request);
    }

    private function reply(array $data): JSONResponse {
        return new JSONResponse($data, ($data['success'] ?? false) ? 200 : 400);
    }

    public function runtimeSettings(): JSONResponse { return $this->reply($this->runtime->call('settings')); }
    public function runtimeStatus(): JSONResponse { return $this->reply($this->runtime->call('status')); }

    public function save(string $settings = '{}', string $url = '', string $days = '', string $time = ''): JSONResponse {
        $values = json_decode($settings, true);
        if (!is_array($values) || strlen($settings) > 16384) {
            return $this->reply(['success' => false, 'error' => 'Invalid settings']);
        }
        // Compatibility for the earlier schedule endpoint. URLs are no longer scraped for status.
        if ($days !== '') { $values['days'] = $days; }
        if ($time !== '') { $values['time'] = $time; }
        $result = $this->runtime->call('save', $values);
        if ($result['success'] ?? false) {
            if ($result['settings']['destination'] !== 'ssh' || $result['settings']['credential_mode'] !== 'managed') {
                $this->config->deleteAppValue('backupstatus', 'provider_request_id');
                $this->config->deleteAppValue('backupstatus', 'recovery_request_id');
            }
            foreach (['destination' => 'destination_type', 'credential_mode' => 'credential_mode'] as $field => $appKey) {
                $this->config->setAppValue('backupstatus', $appKey, (string)$result['settings'][$field]);
            }
        }
        return $this->reply($result);
    }

    public function trust(string $key = '', string $fingerprint = ''): JSONResponse {
        return $this->reply($this->runtime->call('trust', compact('key', 'fingerprint')));
    }

    public function job(string $id = ''): JSONResponse { return $this->reply($this->runtime->call('job', ['id' => $id])); }
    public function cancel(string $id = ''): JSONResponse { return $this->reply($this->runtime->call('cancel', ['id' => $id])); }
    public function runBackup(): JSONResponse { return $this->reply($this->runtime->call('enqueue', ['action' => 'backup'])); }
    public function testConnection(): JSONResponse { return $this->reply($this->runtime->call('enqueue', ['action' => 'test'])); }
    public function restoreInventory(): JSONResponse { return $this->reply($this->runtime->call('inventory')); }

    public function restoreExecute(string $type = 'complete', string $generation = '', string $databaseBackup = '', string $confirm = ''): JSONResponse {
        return $this->reply($this->runtime->call('enqueue', ['action' => 'restore', 'type' => $type,
            'generation' => $generation ?: $databaseBackup, 'confirm' => $confirm]));
    }
    public function disasterRecovery(string $mode = 'verify', string $generation = '', string $confirm = ''): JSONResponse {
        if (!in_array($mode, ['verify', 'restore'], true)) { return $this->reply(['success' => false, 'error' => 'Invalid recovery action']); }
        return $this->reply($this->runtime->call('enqueue', ['action' => $mode, 'type' => 'disaster', 'generation' => $generation, 'confirm' => $confirm]));
    }

    private function validProviderUrl(string $url): bool {
        return strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_QUERY) === null && parse_url($url, PHP_URL_FRAGMENT) === null
            && in_array(parse_url($url, PHP_URL_PATH), [null, '', '/'], true);
    }

    private function provider(string $method, string $path, array $body = [], string $token = ''): array {
        $url = rtrim($this->config->getAppValue('backupstatus', 'provider_url', ''), '/');
        if (!$this->validProviderUrl($url)) {
            throw new \RuntimeException('Configure a valid HTTPS provider URL');
        }
        $options = ['headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'timeout' => 30, 'allow_redirects' => false, 'http_errors' => false];
        if ($token !== '') { $options['headers']['Authorization'] = 'Bearer ' . $token; }
        if ($method === 'post') { $options['body'] = json_encode($body); }
        $response = $this->clientService->newClient()->$method($url . $path, $options);
        $result = json_decode((string)$response->getBody(), true);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || !is_array($result) || !($result['success'] ?? false)) {
            throw new \RuntimeException('Provider request failed; check provider administration and connectivity');
        }
        return $result;
    }

    public function requestProvider(string $consent = '0', string $providerUrl = '', string $providerEmail = ''): JSONResponse {
        if ($consent !== '1' || !$this->validProviderUrl($providerUrl)) {
            return $this->reply(['success' => false, 'error' => 'Consent and a valid HTTPS provider URL are required']);
        }
        $email = (string)$this->userSession->getUser()?->getEMailAddress();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($providerEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->reply(['success' => false, 'error' => 'Valid account and provider notification email addresses are required']);
        }
        try {
            $info = $this->runtime->helper('request-info');
            if (!($info['success'] ?? false)) { return $this->reply($info); }
            $this->config->setAppValue('backupstatus', 'provider_url', rtrim($providerUrl, '/'));
            $this->config->setAppValue('backupstatus', 'provider_email', $providerEmail);
            $data = $this->provider('post', '/api/v1/requests', [
                'source_id' => $info['source_id'], 'public_key' => $info['public_key'],
                'restore_public_key' => $info['restore_public_key'], 'requester_email' => $email,
                'source_url' => $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . \OC::$WEBROOT . '/',
                'client_version' => '0.2.0', 'nextcloud_version' => $this->serverVersion->getVersionString(),
            ]);
            foreach (['request_id', 'request_token'] as $field) {
                $this->config->setAppValue('backupstatus', 'provider_' . $field, (string)$data[$field]);
            }
            $this->config->setAppValue('backupstatus', 'provider_request_status', 'pending');
            $notification = $this->notifyProvider((string)$data['request_id']);
            return $this->reply(['success' => true, 'status' => 'pending', 'notificationSent' => $notification]);
        } catch (Throwable) {
            return $this->reply(['success' => false, 'error' => 'Enrollment failed; verify configuration or check for a pending request at the provider']);
        }
    }

    private function notifyProvider(string $requestId): bool {
        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$this->config->getAppValue('backupstatus', 'provider_email', '')]);
            $message->setSubject('Backup Manager request ' . $requestId);
            $message->setPlainBody('A Backup Manager request awaits review: ' . $requestId . "\n\n"
                . 'Sign in to provider administration and verify the requester through a trusted channel before approval.' . "\n"
                . rtrim($this->config->getAppValue('backupstatus', 'provider_url', ''), '/') . '/admin/');
            $this->mailer->send($message);
            return true;
        } catch (Throwable) { return false; }
    }

    public function resendProviderNotification(): JSONResponse {
        $id = $this->config->getAppValue('backupstatus', 'provider_request_id', '');
        if ($id === '') { return $this->reply(['success' => false, 'error' => 'No pending request']); }
        return $this->reply(['success' => $this->notifyProvider($id)]);
    }

    public function providerStatus(): JSONResponse { return $this->poll(false); }
    public function recoveryStatus(): JSONResponse { return $this->poll(true); }

    private function poll(bool $recovery): JSONResponse {
        $prefix = $recovery ? 'recovery' : 'provider';
        $id = $this->config->getAppValue('backupstatus', $prefix . '_request_id', '');
        if ($id === '') {
            return $this->reply(['success' => true, 'status' => $this->config->getAppValue('backupstatus', $prefix . '_request_status', 'none')]);
        }
        $token = $this->config->getAppValue('backupstatus', $prefix . '_request_token', '');
        try {
            $result = $this->provider('get', '/api/v1/' . ($recovery ? 'recovery-requests/' : 'requests/') . rawurlencode($id), [], $token);
            $status = (string)$result['status'];
            if ($status === 'approved' && !empty($result['connection'])) {
                if ($recovery) {
                    $activated = $this->runtime->helper('activate-recovery-key');
                    if (!($activated['success'] ?? false)) { return $this->reply($activated); }
                }
                $applied = $this->runtime->helper('apply-provider-config', $result['connection']);
                if (!($applied['success'] ?? false)) { return $this->reply($applied); }
                $this->config->setAppValue('backupstatus', 'provider_client_id', (string)$result['connection']['client_id']);
                $this->config->setAppValue('backupstatus', 'provider_request_token', $token);
                $this->config->setAppValue('backupstatus', 'provider_request_status', 'approved');
                $this->config->setAppValue('backupstatus', 'credential_mode', 'managed');
                $this->config->setAppValue('backupstatus', 'destination_type', 'ssh');
            }
            $this->config->setAppValue('backupstatus', $prefix . '_request_status', $status);
            if ($status !== 'pending') { $this->config->deleteAppValue('backupstatus', $prefix . '_request_id'); }
            return $this->reply(['success' => true, 'status' => $status]);
        } catch (Throwable) { return $this->reply(['success' => false, 'error' => 'Provider status unavailable']); }
    }

    public function requestRecovery(string $clientId = '', string $providerUrl = ''): JSONResponse {
        if ($clientId === '') { $clientId = $this->config->getAppValue('backupstatus', 'provider_client_id', ''); }
        if (!preg_match('/^BM-[0-9]{6}$/D', $clientId)) { return $this->reply(['success' => false, 'error' => 'Existing client ID required']); }
        if ($providerUrl !== '') {
            if (!$this->validProviderUrl($providerUrl)) {
                return $this->reply(['success' => false, 'error' => 'HTTPS provider URL required']);
            }
            $this->config->setAppValue('backupstatus', 'provider_url', rtrim($providerUrl, '/'));
        }
        try {
            $info = $this->runtime->helper('recovery-request-info');
            if (!($info['success'] ?? false)) { return $this->reply($info); }
            $result = $this->provider('post', '/api/v1/recovery-requests', ['client_id' => $clientId,
                'source_id' => $info['source_id'], 'public_key' => $info['public_key'], 'restore_public_key' => $info['restore_public_key']]);
            $this->config->setAppValue('backupstatus', 'recovery_request_id', (string)$result['recovery_request_id']);
            $this->config->setAppValue('backupstatus', 'recovery_request_token', (string)$result['request_token']);
            $this->config->setAppValue('backupstatus', 'recovery_request_status', 'pending');
            return $this->reply(['success' => true, 'status' => 'pending']);
        } catch (Throwable) { return $this->reply(['success' => false, 'error' => 'Recovery request failed; check pending requests at the provider']); }
    }

    public function removeBackupManager(string $removeLocal = '0', string $removeRemote = '0', string $confirm = ''): JSONResponse {
        if ($removeLocal !== '1' && $removeRemote !== '1') { return $this->reply(['success' => false, 'error' => 'Select a removal option']); }
        $remote = null;
        if ($removeRemote === '1') {
            if ($confirm !== 'DELETE') { return $this->reply(['success' => false, 'error' => 'Type DELETE to confirm']); }
            $settings = $this->runtime->call('settings');
            if (!($settings['success'] ?? false)) { return $this->reply($settings); }
            $cfg = $settings['settings'];
            if ($cfg['destination'] === 'ssh' && $cfg['credential_mode'] === 'managed') {
                $service = new ProviderRemovalService($this->clientService,
                    $this->config->getAppValue('backupstatus', 'provider_url', ''),
                    $this->config->getAppValue('backupstatus', 'provider_client_id', ''),
                    $this->config->getAppValue('backupstatus', 'provider_request_token', ''));
                $outcome = $service->remove();
                if (!$outcome->success) { return $this->reply(['success' => false, 'error' => 'Provider deletion request failed']); }
                $remote = $outcome->status;
            } else {
                if ($removeLocal === '1') { return $this->reply(['success' => false, 'error' => 'Complete remote deletion before removing the local client']); }
                return $this->reply($this->runtime->call('enqueue', ['action' => 'delete', 'confirm' => 'DELETE']));
            }
        }
        if ($removeLocal === '1') {
            exec('sudo -n /usr/local/sbin/backupmanager-uninstall-client purge 2>/dev/null', $output, $code);
            if ($code !== 0) { return $this->reply(['success' => false, 'error' => 'Unable to schedule local removal']); }
        }
        return $this->reply(['success' => true, 'remote' => $remote, 'localRemovalScheduled' => $removeLocal === '1']);
    }
    public function managementClients(): JSONResponse {
        $providerUrl = rtrim(
            $this->config->getAppValue("backupstatus", "provider_url", ""),
            "/"
        );

        $tokenFile = "/etc/backupmanager/management-token";

        if (!$this->validProviderUrl($providerUrl) || !is_readable($tokenFile)) {
            return new JSONResponse([
                "success" => false,
                "error" => "Management connection unavailable",
            ], 503);
        }

        $managementToken = trim((string)file_get_contents($tokenFile));

        if ($managementToken === "") {
            return new JSONResponse([
                "success" => false,
                "error" => "Management authentication unavailable",
            ], 503);
        }

        try {
            $client = $this->clientService->newClient();

            $response = $client->get(
                $providerUrl . "/api/v1/management/clients",
                [
                    "headers" => [
                        "Accept" => "application/json",
                        "Authorization" => "Bearer " . $managementToken,
                    ],
                    "timeout" => 30,
                    "http_errors" => false,
                    "allow_redirects" => false,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Unable to retrieve provider clients",
                ], 502);
            }

            $data = json_decode((string)$response->getBody(), true);

            if (!is_array($data) || !($data["success"] ?? false)) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Invalid provider response",
                ], 502);
            }

            return new JSONResponse([
                "success" => true,
                "clients" => $data["clients"] ?? [],
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse([
                "success" => false,
                "error" => "Provider unavailable",
            ], 502);
        }
    }


    public function managementClientAction(
        string $clientId,
        string $clientAction
    ): JSONResponse {
        $providerUrl = rtrim(
            $this->config->getAppValue("backupstatus", "provider_url", ""),
            "/"
        );

        $tokenFile = "/etc/backupmanager/management-token";

        if (
            !$this->validProviderUrl($providerUrl)
            || !is_readable($tokenFile)
        ) {
            return new JSONResponse([
                "success" => false,
                "error" => "Management connection unavailable",
            ], 503);
        }

        if (
            !preg_match('/^BM-[0-9]{6}$/', $clientId)
            || !in_array($clientAction, ["pause", "resume", "remove", "delete"], true)
        ) {
            return new JSONResponse([
                "success" => false,
                "error" => "Invalid client action",
            ], 400);
        }

        $managementToken = trim((string)file_get_contents($tokenFile));

        try {
            $client = $this->clientService->newClient();

            $url = $providerUrl
                . "/api/v1/management/clients/"
                . rawurlencode($clientId);

            $options = [
                "headers" => [
                    "Accept" => "application/json",
                    "Authorization" => "Bearer " . $managementToken,
                ],
                "timeout" => 30,
                "http_errors" => false,
                    "allow_redirects" => false,
            ];

            if ($clientAction === "delete") {
                $response = $client->delete($url, $options);
            } else {
                $response = $client->post(
                    $url . "/" . rawurlencode($clientAction),
                    $options
                );
            }

            $statusCode = $response->getStatusCode();
            $data = json_decode((string)$response->getBody(), true);

            if (
                $statusCode < 200
                || $statusCode >= 300
                || !is_array($data)
                || !($data["success"] ?? false)
            ) {
                return new JSONResponse([
                    "success" => false,
                    "error" => $data["error"]
                        ?? "Provider action failed",
                ], 502);
            }

            return new JSONResponse($data);
        } catch (Throwable $e) {
            return new JSONResponse([
                "success" => false,
                "error" => "Provider unavailable",
            ], 502);
        }
    }


}
