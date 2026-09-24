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
        $data['error_message'] = \OCA\BackupStatus\Service\ErrorMessages::message($data);
        if (($data['success'] ?? false) === false) {
            $data['error_code'] ??= 'operation_failed';
            error_log('Backup Manager settings request failed: ' . $data['error_code']);
        }
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
        try {
            $response = $this->clientService->newClient()->request(strtoupper($method), $url . $path, $options);
        } catch (Throwable $error) {
            // HTTP exception bodies and URLs can contain credentials; log metadata only.
            error_log('Backup Manager provider transport failure: ' . get_class($error));
            throw new \RuntimeException('Provider transport unavailable', 502);
        }
        $result = $this->providerJson($response);
        return $result;
    }

    private function providerJson($response): array {
        $status = $response->getStatusCode();
        $type = strtolower(trim(explode(';', $response->getHeader('Content-Type'))[0]));
        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        if ($type !== 'application/json' || !is_array($result) || !is_bool($result['success'] ?? null)) {
            // Log metadata only: a misrouted response can contain PHP source or secrets.
            error_log('Backup Manager invalid provider response: HTTP=' . $status
                . '; JSON-content-type=' . ($type === 'application/json' ? 'yes' : 'no')
                . '; bytes=' . strlen($body) . '; JSON-envelope=' . (is_array($result) ? 'yes' : 'no'));
            throw new \UnexpectedValueException('Invalid provider API response', 502);
        }
        if ($status < 200 || $status >= 300 || $result['success'] !== true) {
            error_log('Backup Manager provider request rejected: HTTP=' . $status);
            throw new \RuntimeException('Provider HTTP ' . $status, $status);
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
                'client_version' => '0.2.4', 'nextcloud_version' => $this->serverVersion->getVersionString(),
            ]);
            if (!is_string($data['request_id'] ?? null) || !preg_match('/^REQ-[A-Za-z0-9-]+$/D', $data['request_id'])
                || !is_string($data['request_token'] ?? null) || $data['request_token'] === ''
                || ($data['status'] ?? null) !== 'pending') {
                throw new \UnexpectedValueException('Invalid provider enrollment response', 502);
            }
            foreach (['request_id', 'request_token'] as $field) {
                $this->config->setAppValue('backupstatus', 'provider_' . $field, (string)$data[$field]);
            }
            $this->config->setAppValue('backupstatus', 'provider_request_status', 'pending');
            $notification = $this->notifyProvider((string)$data['request_id']);
            return $this->reply(['success' => true, 'status' => 'pending', 'notificationSent' => $notification]);
        } catch (Throwable $error) {
            $code = $error instanceof \UnexpectedValueException ? 'provider_invalid_response' : 'provider_unavailable';
            $this->config->deleteAppValue('backupstatus', 'provider_request_id');
            $this->config->setAppValue('backupstatus', 'provider_request_status', 'failed');
            $this->config->setAppValue('backupstatus', 'provider_request_error', $code);
            return $this->reply(['success' => false, 'error_code' => $code]);
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

    public function refreshProvider(): JSONResponse {
        $clientId = $this->config->getAppValue('backupstatus', 'provider_client_id', '');
        $token = $this->config->getAppValue('backupstatus', 'provider_request_token', '');
        if (!preg_match('/^BM-[0-9]{6}$/D', $clientId) || $token === ''
            || $this->config->getAppValue('backupstatus', 'recovery_request_id', '') !== '') {
            return $this->reply(['success' => false, 'error' => 'An activated provider client is required']);
        }
        try {
            $result = $this->provider('get', '/api/v1/clients/' . $clientId . '/connection', [], $token);
            $connection = $result['connection'] ?? [];
            if (($connection['client_id'] ?? null) !== $clientId) {
                throw new \UnexpectedValueException('Provider returned a different client', 502);
            }
            // Refresh only the assignment. Never replay recovery or activate staged keys.
            $applied = $this->runtime->helper('apply-provider-config', array_intersect_key($connection,
                array_flip(['client_id', 'host', 'port', 'user', 'path'])) + [
                    'select_managed' => true, 'activate_recovery' => false]);
            if (!($applied['success'] ?? false)) { return $this->reply($applied); }
            $this->config->setAppValue('backupstatus', 'credential_mode', 'managed');
            $this->config->setAppValue('backupstatus', 'destination_type', 'ssh');
            return $this->reply(['success' => true, 'clientId' => $clientId, 'applied' => true]);
        } catch (Throwable $error) {
            return $this->reply(['success' => false, 'error_code' => $error instanceof \UnexpectedValueException
                ? 'provider_invalid_response' : 'provider_status_failed']);
        }
    }

    private function poll(bool $recovery): JSONResponse {
        $prefix = $recovery ? 'recovery' : 'provider';
        // Recovery supersedes onboarding. Never apply an old enrollment while
        // replacement credentials are being requested or approved.
        if (!$recovery && $this->config->getAppValue('backupstatus', 'recovery_request_id', '') !== '') {
            return $this->reply(['success' => true, 'status' => 'stale']);
        }
        $id = $this->config->getAppValue('backupstatus', $prefix . '_request_id', '');
        if ($id === '') {
            $status = $this->config->getAppValue('backupstatus', $prefix . '_request_status', 'none');
            if ($status === 'failed') {
                return $this->reply(['success' => false, 'status' => 'failed',
                    'error_code' => $this->config->getAppValue('backupstatus', $prefix . '_request_error', 'recovery_request_failed')]);
            }
            // A pending status without a request ID cannot be polled and is stale.
            if ($status === 'pending') {
                $status = 'stale';
                $this->config->setAppValue('backupstatus', $prefix . '_request_status', $status);
            }
            return $this->reply(['success' => true, 'status' => $status,
                'clientId' => $this->config->getAppValue('backupstatus', 'provider_client_id', '')]);
        }
        $token = $this->config->getAppValue('backupstatus', $prefix . '_request_token', '');
        try {
            $result = $this->provider('get', '/api/v1/' . ($recovery ? 'recovery-requests/' : 'requests/') . rawurlencode($id), [], $token);
            // A newer request or completed recovery may have superseded this poll.
            if ($this->config->getAppValue('backupstatus', $prefix . '_request_id', '') !== $id
                || $this->config->getAppValue('backupstatus', $prefix . '_request_token', '') !== $token) {
                return $this->reply(['success' => true,
                    'status' => $this->config->getAppValue('backupstatus', $prefix . '_request_status', 'none'),
                    'clientId' => $this->config->getAppValue('backupstatus', 'provider_client_id', '')]);
            }
            if (!$recovery && $this->config->getAppValue('backupstatus', 'recovery_request_id', '') !== '') {
                return $this->reply(['success' => true, 'status' => 'stale']);
            }
            $responseId = $result[$recovery ? 'recovery_request_id' : 'request_id'] ?? null;
            if ($responseId !== $id || !in_array($result['status'] ?? null, ['pending', 'approved', 'rejected', 'expired'], true)) {
                throw new \UnexpectedValueException('Invalid provider status response', 502);
            }
            $status = $result['status'];
            if ($status === 'approved' && empty($result['connection'])) {
                return $this->reply(['success' => false, 'error' => 'Approved request has no connection configuration']);
            }
            if ($status === 'approved') {
                $connection = $result['connection'];
                $connection['activate_recovery'] = $recovery;
                $applied = $this->runtime->helper('apply-provider-config', $connection);
                if (!($applied['success'] ?? false)) { return $this->reply($applied); }
                $this->config->setAppValue('backupstatus', 'provider_client_id', (string)$result['connection']['client_id']);
                $this->config->setAppValue('backupstatus', 'provider_request_token', $token);
                if ($recovery) { $this->config->deleteAppValue('backupstatus', 'provider_request_id'); }
                $this->config->setAppValue('backupstatus', 'provider_request_status', 'approved');
                $this->config->setAppValue('backupstatus', 'credential_mode', 'managed');
                $this->config->setAppValue('backupstatus', 'destination_type', 'ssh');
            }
            $this->config->setAppValue('backupstatus', $prefix . '_request_status', $status);
            if ($status !== 'pending') { $this->config->deleteAppValue('backupstatus', $prefix . '_request_id'); }
            return $this->reply(['success' => true, 'status' => $status, 'applied' => $status === 'approved',
                'clientId' => $this->config->getAppValue('backupstatus', 'provider_client_id', '')]);
        } catch (Throwable $error) {
            error_log('Backup Manager ' . $prefix . ' poll failed: ' . get_class($error) . '; code=' . $error->getCode());
            return $this->reply(['success' => false, 'error_code' => $error instanceof \UnexpectedValueException ? 'provider_invalid_response' : 'provider_status_failed', 'error' => 'Provider status unavailable']);
        }
    }

    public function requestRecovery(string $clientId = '', string $providerUrl = '', string $consent = '0'): JSONResponse {
        if ($consent !== '1') { return $this->reply(['success' => false, 'error_code' => 'consent_required', 'error' => 'Consent is required']); }
        if ($clientId === '') { $clientId = $this->config->getAppValue('backupstatus', 'provider_client_id', ''); }
        if (!preg_match('/^BM-[0-9]{6}$/D', $clientId)) { return $this->reply(['success' => false, 'error_code' => 'invalid_client_id', 'error' => 'Existing client ID required']); }
        $previousProviderUrl = $this->config->getAppValue('backupstatus', 'provider_url', '');
        if ($providerUrl !== '') {
            if (!$this->validProviderUrl($providerUrl)) {
                return $this->reply(['success' => false, 'error_code' => 'provider_url_invalid', 'error' => 'HTTPS provider URL required']);
            }
            $this->config->setAppValue('backupstatus', 'provider_url', rtrim($providerUrl, '/'));
        }
        $previous = null;
        if ($this->config->getAppValue('backupstatus', 'recovery_request_validated', '') === '1'
            && $previousProviderUrl === $this->config->getAppValue('backupstatus', 'provider_url', '')
            && $clientId === $this->config->getAppValue('backupstatus', 'provider_client_id', '')) {
            $previous = [$this->config->getAppValue('backupstatus', 'recovery_request_id', ''),
                         $this->config->getAppValue('backupstatus', 'recovery_request_token', '')];
        }
        $this->config->deleteAppValue('backupstatus', 'recovery_request_validated');
        // Retire obsolete local polling before submitting replacement keys. This does
        // not cancel/delete any provider request or change the permanent client ID.
        $this->config->deleteAppValue('backupstatus', 'recovery_request_id');
        $this->config->deleteAppValue('backupstatus', 'recovery_request_token');
        $this->config->deleteAppValue('backupstatus', 'provider_request_id');
        $this->config->setAppValue('backupstatus', 'recovery_request_status', 'failed');
        $this->config->setAppValue('backupstatus', 'recovery_request_error', 'recovery_request_failed');
        try {
            $info = $this->runtime->helper('recovery-request-info');
            if (!($info['success'] ?? false)) { return $this->reply($info); }
            $result = $this->provider('post', '/api/v1/recovery-requests', ['client_id' => $clientId,
                'source_id' => $info['source_id'], 'public_key' => $info['public_key'], 'restore_public_key' => $info['restore_public_key']]);
            if (($result['status'] ?? null) !== 'pending' || !is_string($result['recovery_request_id'] ?? null) || !preg_match('/^REC-[A-Za-z0-9-]+$/D', $result['recovery_request_id'])
                || !is_string($result['request_token'] ?? null) || $result['request_token'] === '') {
                throw new \UnexpectedValueException('Incomplete provider recovery response', 502);
            }
            $this->config->setAppValue('backupstatus', 'recovery_request_id', (string)$result['recovery_request_id']);
            $this->config->setAppValue('backupstatus', 'recovery_request_token', (string)$result['request_token']);
            $this->config->setAppValue('backupstatus', 'recovery_request_status', 'pending');
            $this->config->deleteAppValue('backupstatus', 'recovery_request_error');
            $this->config->setAppValue('backupstatus', 'recovery_request_validated', '1');
            return $this->reply(['success' => true, 'status' => 'pending', 'requestId' => $result['recovery_request_id']]);
        } catch (Throwable $error) {
            $code = $error instanceof \UnexpectedValueException ? 'provider_invalid_response' : match ((int)$error->getCode()) {
                400 => 'provider_invalid_request', 409 => 'recovery_pending', 429 => 'provider_rate_limited', 403 => 'provider_forbidden',
                404 => 'provider_not_found', 502, 503 => 'provider_unavailable', default => 'recovery_request_failed',
            };
            if ($code === 'recovery_pending' && $previous !== null && $previous[0] !== '' && $previous[1] !== '') {
                // A duplicate submission must not orphan a request whose credentials
                // this client already received in a validated JSON response.
                $this->config->setAppValue('backupstatus', 'recovery_request_id', $previous[0]);
                $this->config->setAppValue('backupstatus', 'recovery_request_token', $previous[1]);
                $this->config->setAppValue('backupstatus', 'recovery_request_status', 'pending');
                $this->config->setAppValue('backupstatus', 'recovery_request_validated', '1');
            }
            $this->config->setAppValue('backupstatus', 'recovery_request_error', $code);
            error_log('Backup Manager recovery submission failed: ' . $code . '; exception=' . get_class($error) . '; HTTP=' . $error->getCode());
            return $this->reply(['success' => false, 'error_code' => $code,
                'error_detail' => 'Recovery submission; HTTP=' . (int)$error->getCode(),
                'error' => 'Recovery request failed']);
        }
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

            $response = $client->request('GET',
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

            $data = $this->providerJson($response);

            if (!is_array($data['clients'] ?? null)) {
                throw new \UnexpectedValueException('Invalid provider clients response', 502);
            }

            return new JSONResponse([
                "success" => true,
                "clients" => $data["clients"] ?? [],
            ]);
        } catch (\Throwable $e) {
            return $this->reply(['success' => false, 'error_code' => $e instanceof \UnexpectedValueException
                ? 'provider_invalid_response' : 'provider_unavailable']);
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
                $response = $client->request('DELETE', $url, $options);
            } else {
                $response = $client->request('POST',
                    $url . "/" . rawurlencode($clientAction),
                    $options
                );
            }

            $statusCode = $response->getStatusCode();
            $data = $this->providerJson($response);

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
            return $this->reply(['success' => false, 'error_code' => $e instanceof \UnexpectedValueException
                ? 'provider_invalid_response' : 'provider_unavailable']);
        }
    }


}
