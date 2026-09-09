<?php

declare(strict_types=1);

namespace OCA\BackupStatus\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\HintException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\ServerVersion;
use Throwable;

final class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IL10N $l10n,
        private IMailer $mailer,
        private IClientService $clientService,
        private ServerVersion $serverVersion,
        private IUserSession $userSession,
    ) {
        parent::__construct('backupstatus', $request);
    }

    public function save(string $url = '', string $days = '', string $time = '02:00'): JSONResponse {
        $url = trim($url);

        $allowedDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $selectedDays = array_values(array_intersect(
            $allowedDays,
            array_filter(array_map('trim', explode(',', $days)))
        ));

        if ($selectedDays === []) {
            return new JSONResponse([
                'success' => false,
                'label' => 'Failed',
                'error' => $this->l10n->t('Select at least one backup day'),
            ], 400);
        }

        if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time) !== 1) {
            return new JSONResponse([
                'success' => false,
                'label' => 'Failed',
                'error' => $this->l10n->t('Invalid time'),
            ], 400);
        }

        $this->config->setAppValue('backupstatus', 'backup_url', $url);
        $this->config->setAppValue('backupstatus', 'backup_days', implode(',', $selectedDays));
        $this->config->setAppValue('backupstatus', 'backup_time', $time);

        $schedule = $this->applySchedule(implode(',', $selectedDays), $time);
        if (!$schedule['success']) {
            return new JSONResponse([
                'success' => false,
                'label' => 'Failed',
                'error' => $schedule['error'],
            ], 500);
        }

        $connected = $this->testConnection();
        $this->config->setAppValue('backupstatus', 'connected', $connected ? '1' : '0');
        $this->config->setAppValue('backupstatus', 'checked_at', (string)time());

        return new JSONResponse([
            'success' => $connected,
            'label' => $connected ? 'Success' : 'Failed',
            'allowLocalRemoteServers' => $this->config->getSystemValueBool('allow_local_remote_servers', false),
            'days' => $selectedDays,
            'time' => $time,
        ]);
    }

    public function enableLocalRemote(): JSONResponse {
        try {
            $this->config->setSystemValue('allow_local_remote_servers', true);
        } catch (HintException|Throwable $e) {
            return new JSONResponse([
                'success' => false,
                'label' => 'Failed',
            ], 500);
        }

        return new JSONResponse([
            'success' => $this->config->getSystemValueBool('allow_local_remote_servers', false),
        ]);
    }

    public function requestProvider(
        string $consent = "0",
        string $providerUrl = "",
        string $providerEmail = ""
    ): JSONResponse {
        if ($consent !== "1") {
            return new JSONResponse([
                "success" => false,
                "error" => $this->l10n->t("Consent is required"),
            ], 400);
        }

        $providerUrl = rtrim(trim($providerUrl), "/");
        $providerEmail = trim($providerEmail);

        $user = $this->userSession->getUser();
        $requesterEmail = $user !== null
            ? trim((string)$user->getEMailAddress())
            : "";

        if (filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new JSONResponse([
                "success" => false,
                "error" => $this->l10n->t(
                    "Your Nextcloud account does not have a valid email address"
                ),
            ], 400);
        }

        if (
            filter_var($providerUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string)parse_url($providerUrl, PHP_URL_SCHEME)) !== "https"
        ) {
            return new JSONResponse([
                "success" => false,
                "error" => $this->l10n->t("Enter a valid HTTPS provider URL"),
            ], 400);
        }

        if (filter_var($providerEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new JSONResponse([
                "success" => false,
                "error" => $this->l10n->t("Enter a valid approval email address"),
            ], 400);
        }

        $process = proc_open(
            ["sudo", "-n", "/usr/local/sbin/backupmanager-request-info"],
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return new JSONResponse([
                "success" => false,
                "error" => "Request helper could not be started",
            ], 500);
        }

        $output = trim((string)stream_get_contents($pipes[1]));
        $error = trim((string)stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return new JSONResponse([
                "success" => false,
                "error" => $error !== "" ? $error : "Request information unavailable",
            ], 500);
        }

        $parts = explode("\t", $output, 3);

        if (count($parts) !== 3) {
            return new JSONResponse([
                "success" => false,
                "error" => "Invalid request information",
            ], 500);
        }

        [$sourceId, $unusedBackupHost, $publicKey] = $parts;

        $sourceUrl =
            $this->request->getServerProtocol()
            . "://"
            . $this->request->getServerHost()
            . \OC::$WEBROOT
            . "/";

        $payload = [
            "source_id" => $sourceId,
            "source_url" => $sourceUrl,
            "public_key" => $publicKey,
            "client_version" => "0.1.0-beta3",
            "nextcloud_version" => $this->serverVersion->getVersionString(),
            "approval_email" => $providerEmail,
            "requester_email" => $requesterEmail,
        ];

        try {
            $client = $this->clientService->newClient();

            $response = $client->post(
                $providerUrl . "/api/v1/requests",
                [
                    "headers" => [
                        "Accept" => "application/json",
                        "Content-Type" => "application/json",
                    ],
                    "body" => json_encode(
                        $payload,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                    "timeout" => 30,
                ]
            );

            $data = json_decode((string)$response->getBody(), true);

            if (
                !is_array($data)
                || !($data["success"] ?? false)
                || empty($data["request_id"])
                || empty($data["request_token"])
                || empty($data["approval_token"])
            ) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Invalid response from backup provider",
                ], 502);
            }

            $this->config->setAppValue("backupstatus", "provider_url", $providerUrl);
            $this->config->setAppValue("backupstatus", "provider_email", $providerEmail);
            $this->config->setAppValue("backupstatus", "provider_consent", "1");
            $this->config->setAppValue("backupstatus", "provider_request_sent", "1");
            $this->config->setAppValue(
                "backupstatus",
                "provider_request_id",
                (string)$data["request_id"]
            );
            $this->config->setAppValue(
                "backupstatus",
                "provider_request_token",
                (string)$data["request_token"]
            );
            $this->config->setAppValue(
                "backupstatus",
                "provider_request_status",
                (string)($data["status"] ?? "pending")
            );
            $this->config->setAppValue(
                "backupstatus",
                "provider_request_time",
                (string)time()
            );

            try {
                $message = $this->mailer->createMessage();
                $message->setTo([$providerEmail]);
                $message->setSubject(
                    "Backup Manager approval request " . (string)$data["request_id"]
                );
                $message->setPlainBody(
                    "A new Backup Manager client is requesting access.\n\n"
                    . "Request ID: " . (string)$data["request_id"] . "\n"
                    . "Source: " . $sourceId . "\n"
                    . "Source URL: " . $sourceUrl . "\n"
                    . "SSH fingerprint: " . trim((string)shell_exec(
                        "printf %s " . escapeshellarg($publicKey)
                        . " | ssh-keygen -lf - -E sha256 2>/dev/null | awk '{print $2}'"
                    )) . "\n"
                    . "Nextcloud version: " . $this->serverVersion->getVersionString() . "\n"
                    . "Backup Manager version: 0.1.0-beta3\n"
                    . "Status: pending approval\n\n"
                    . "Approve:\n"
                    . $providerUrl . "/approval/" . rawurlencode((string)$data["request_id"])
                    . "/approve?token=" . rawurlencode((string)$data["approval_token"]) . "\n\n"
                    . "Reject:\n"
                    . $providerUrl . "/approval/" . rawurlencode((string)$data["request_id"])
                    . "/reject?token=" . rawurlencode((string)$data["approval_token"]) . "\n\n"
                    . "These links are one-time approval links and expire with the request."
                );
                $this->mailer->send($message);
            } catch (Throwable $mailError) {
                $this->config->setAppValue(
                    "backupstatus",
                    "provider_notification_error",
                    $mailError->getMessage()
                );
            }

            return new JSONResponse([
                "success" => true,
                "requestId" => $data["request_id"],
                "status" => $data["status"] ?? "pending",
            ]);
        } catch (Throwable $e) {
            return new JSONResponse([
                "success" => false,
                "error" => "Provider request failed: " . $e->getMessage(),
            ], 502);
        }
    }

    public function providerStatus(): JSONResponse {
        $providerUrl = rtrim(
            $this->config->getAppValue("backupstatus", "provider_url", ""),
            "/"
        );
        $requestId = $this->config->getAppValue(
            "backupstatus",
            "provider_request_id",
            ""
        );
        $requestToken = $this->config->getAppValue(
            "backupstatus",
            "provider_request_token",
            ""
        );

        if ($providerUrl === "" || $requestId === "" || $requestToken === "") {
            return new JSONResponse([
                "success" => false,
                "error" => "No provider request available",
            ], 404);
        }

        try {
            $client = $this->clientService->newClient();

            $response = $client->get(
                $providerUrl . "/api/v1/requests/" . rawurlencode($requestId),
                [
                    "headers" => [
                        "Accept" => "application/json",
                        "Authorization" => "Bearer " . $requestToken,
                    ],
                    "timeout" => 30,
                    "http_errors" => false,
                ]
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                foreach (["provider_request_id", "provider_request_token", "provider_request_sent", "provider_request_status"] as $key) {
                    $this->config->deleteAppValue("backupstatus", $key);
                }

                return new JSONResponse([
                    "success" => true,
                    "status" => "rejected",
                    "requestId" => $requestId,
                    "connection" => null,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Provider status check failed",
                ], 502);
            }

            $data = json_decode((string)$response->getBody(), true);

            if (!is_array($data) || !($data["success"] ?? false)) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Invalid status response from backup provider",
                ], 502);
            }

            $status = (string)($data["status"] ?? "unknown");

            $this->config->setAppValue(
                "backupstatus",
                "provider_request_status",
                $status
            );

            if (
                $status === "approved"
                && isset($data["connection"])
                && is_array($data["connection"])
            ) {
                $connection = $data["connection"];

                $this->config->setAppValue(
                    "backupstatus",
                    "provider_client_id",
                    (string)($connection["client_id"] ?? "")
                );
                $this->config->setAppValue(
                    "backupstatus",
                    "backup_host",
                    (string)($connection["host"] ?? "")
                );
                $this->config->setAppValue(
                    "backupstatus",
                    "backup_port",
                    (string)($connection["port"] ?? "22")
                );
                $this->config->setAppValue(
                    "backupstatus",
                    "backup_user",
                    (string)($connection["user"] ?? "")
                );
                $this->config->setAppValue(
                    "backupstatus",
                    "backup_path",
                    (string)($connection["path"] ?? "")
                );


                $clientId = (string)($connection["client_id"] ?? "");
                $host = (string)($connection["host"] ?? "");
                $port = (string)($connection["port"] ?? "22");
                $user = (string)($connection["user"] ?? "");
                $path = (string)($connection["path"] ?? "/");

                $command = sprintf(
                    'sudo /usr/local/sbin/backupmanager-apply-provider-config %s %s %s %s %s',
                    escapeshellarg($clientId),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($user),
                    escapeshellarg($path)
                );

                exec($command, $configOutput, $configExitCode);

                if ($configExitCode !== 0) {
                    return new JSONResponse([
                        "success" => false,
                        "error" => "Approved, but local Backup Manager configuration failed",
                    ], 500);
                }

                /*
                 * Approval completes the onboarding phase. From this point on,
                 * the active client ID and provider token identify the client.
                 * The original request ID must no longer be required.
                 */
                $this->config->deleteAppValue(
                    "backupstatus",
                    "provider_request_id"
                );
                $this->config->deleteAppValue(
                    "backupstatus",
                    "provider_request_sent"
                );
            }

            return new JSONResponse([
                "success" => true,
                "status" => $status,
                "requestId" => $requestId,
                "connection" => $data["connection"] ?? null,
            ]);
        } catch (Throwable $e) {
            return new JSONResponse([
                "success" => false,
                "error" => "Provider status check failed: " . $e->getMessage(),
            ], 502);
        }
    }


    public function removeBackupManager(
        string $removeLocal = "0",
        string $removeRemote = "0",
        string $confirm = ""
    ): JSONResponse {
        $removeLocalEnabled = $removeLocal === "1";
        $removeRemoteEnabled = $removeRemote === "1";

        if (!$removeLocalEnabled && !$removeRemoteEnabled) {
            return new JSONResponse([
                "success" => false,
                "error" => "Nothing selected for removal",
            ], 400);
        }

        if ($removeRemoteEnabled && $confirm !== "DELETE") {
            return new JSONResponse([
                "success" => false,
                "error" => "Remote deletion confirmation is required",
            ], 400);
        }

        $destinationType = $this->config->getAppValue(
            "backupstatus",
            "destination_type",
            "ssh"
        );

        $credentialMode = $this->config->getAppValue(
            "backupstatus",
            "credential_mode",
            "manual"
        );

        $remoteResult = null;

        if ($removeRemoteEnabled) {
            if ($destinationType === "ssh" && $credentialMode === "managed") {
                $providerUrl = rtrim(
                    $this->config->getAppValue(
                        "backupstatus",
                        "provider_url",
                        ""
                    ),
                    "/"
                );

                $clientId = $this->config->getAppValue(
                    "backupstatus",
                    "provider_client_id",
                    ""
                );

                $requestToken = $this->config->getAppValue(
                    "backupstatus",
                    "provider_request_token",
                    ""
                );

                if (
                    $providerUrl === ""
                    || $clientId === ""
                    || $requestToken === ""
                ) {
                    return new JSONResponse([
                        "success" => false,
                        "error" => "Provider registration is incomplete",
                    ], 400);
                }

                try {
                    $client = $this->clientService->newClient();

                    $response = $client->post(
                        $providerUrl
                        . "/api/v1/clients/"
                        . rawurlencode($clientId)
                        . "/deletion-requests",
                        [
                            "headers" => [
                                "Accept" => "application/json",
                                "Authorization" => "Bearer " . $requestToken,
                                "Content-Type" => "application/json",
                            ],
                            "body" => json_encode([
                                "client_id" => $clientId,
                                "delete_storage" => true,
                            ], JSON_UNESCAPED_SLASHES),
                            "timeout" => 30,
                        ]
                    );

                    $data = json_decode(
                        (string)$response->getBody(),
                        true
                    );

                    if (
                        !is_array($data)
                        || !($data["success"] ?? false)
                        || empty($data["deletion_request_id"])
                    ) {
                        return new JSONResponse([
                            "success" => false,
                            "error" => "Invalid response from backup provider",
                        ], 502);
                    }

                    $this->config->setAppValue(
                        "backupstatus",
                        "provider_deletion_request_id",
                        (string)$data["deletion_request_id"]
                    );

                    $this->config->setAppValue(
                        "backupstatus",
                        "provider_deletion_status",
                        (string)($data["status"] ?? "pending")
                    );

                    $remoteResult = [
                        "type" => "provider",
                        "status" => (string)($data["status"] ?? "pending"),
                        "deletionRequestId" => (string)$data["deletion_request_id"],
                    ];
                } catch (Throwable $e) {
                    return new JSONResponse([
                        "success" => false,
                        "error" => "Provider deletion request failed: "
                            . $e->getMessage(),
                    ], 502);
                }
            } else {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Remote deletion for this destination is not implemented yet",
                ], 501);
            }
        }

        if ($removeLocalEnabled) {
            $mode = "purge";

            $process = proc_open(
                [
                    "sudo",
                    "-n",
                    "/usr/local/sbin/backupmanager-uninstall-client",
                    $mode,
                ],
                [
                    1 => ["pipe", "w"],
                    2 => ["pipe", "w"],
                ],
                $pipes
            );

            if (!is_resource($process)) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Local uninstall helper could not be started",
                ], 500);
            }

            $output = trim((string)stream_get_contents($pipes[1]));
            $error = trim((string)stream_get_contents($pipes[2]));

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                return new JSONResponse([
                    "success" => false,
                    "error" => $error !== ""
                        ? $error
                        : "Local uninstall could not be scheduled",
                ], 500);
            }

            return new JSONResponse([
                "success" => true,
                "localRemovalScheduled" => true,
                "remote" => $remoteResult,
                "message" => $output !== ""
                    ? $output
                    : "Removal scheduled",
            ]);
        }

        return new JSONResponse([
            "success" => true,
            "localRemovalScheduled" => false,
            "remote" => $remoteResult,
        ]);
    }


    public function resendProviderNotification(): JSONResponse {
        $providerUrl = rtrim(
            $this->config->getAppValue("backupstatus", "provider_url", ""),
            "/"
        );
        $providerEmail = trim(
            $this->config->getAppValue("backupstatus", "provider_email", "")
        );
        $requestId = $this->config->getAppValue(
            "backupstatus",
            "provider_request_id",
            ""
        );
        $requestToken = $this->config->getAppValue(
            "backupstatus",
            "provider_request_token",
            ""
        );

        if (
            $providerUrl === ""
            || $requestId === ""
            || $requestToken === ""
            || filter_var($providerEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            return new JSONResponse([
                "success" => false,
                "error" => "Provider request information is incomplete",
            ], 400);
        }

        try {
            /*
             * Haal eerst de actuele request-status bij de provider op.
             * Resend mag alleen voor een nog pending aanvraag.
             */
            $client = $this->clientService->newClient();

            $response = $client->get(
                $providerUrl . "/api/v1/requests/" . rawurlencode($requestId),
                [
                    "headers" => [
                        "Accept" => "application/json",
                        "Authorization" => "Bearer " . $requestToken,
                    ],
                    "timeout" => 30,
                ]
            );

            $data = json_decode((string)$response->getBody(), true);

            if (!is_array($data) || !($data["success"] ?? false)) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Unable to verify provider request",
                ], 502);
            }

            $status = (string)($data["status"] ?? "");

            $this->config->setAppValue(
                "backupstatus",
                "provider_request_status",
                $status
            );

            if ($status !== "pending") {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Notification can only be resent for a pending request",
                    "status" => $status,
                ], 409);
            }

            $sourceId = (string)($data["source_id"] ?? "");
            $sourceUrl = (string)($data["source_url"] ?? "");

            $message = $this->mailer->createMessage();
            $message->setTo([$providerEmail]);
            $message->setSubject(
                "Backup Manager approval request " . $requestId
            );

            $message->setPlainBody(
                "A Backup Manager client is awaiting approval.\n\n"
                . "Request ID: " . $requestId . "\n"
                . "Source: " . $sourceId . "\n"
                . "Source URL: " . $sourceUrl . "\n"
                . "Nextcloud version: " . $this->serverVersion->getVersionString() . "\n"
                . "Backup Manager version: 0.1.0-beta3\n"
                . "Status: pending approval\n\n"
                . "This is a notification only. Approval must be performed on the backup provider."
            );

            $this->mailer->send($message);

            $this->config->deleteAppValue(
                "backupstatus",
                "provider_notification_error"
            );

            $this->config->setAppValue(
                "backupstatus",
                "provider_notification_time",
                (string)time()
            );

            return new JSONResponse([
                "success" => true,
                "requestId" => $requestId,
                "status" => "pending",
                "notificationSent" => true,
            ]);
        } catch (Throwable $e) {
            $this->config->setAppValue(
                "backupstatus",
                "provider_notification_error",
                $e->getMessage()
            );

            return new JSONResponse([
                "success" => false,
                "error" => "Notification could not be sent: " . $e->getMessage(),
            ], 502);
        }
    }


    /**
     * @return array{success: bool, error: string}
     */
    private function applySchedule(string $days, string $time): array {
        $command = [
            'sudo',
            '-n',
            '/usr/local/sbin/backupmanager-schedule',
            $days,
            $time,
        ];

        $pipes = [];
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Planning-helper kon niet worden gestart'];
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'error' => trim($error) !== '' ? trim($error) : 'Planning kon niet worden toegepast',
            ];
        }

        return ['success' => true, 'error' => trim($output)];
    }

    private function testConnection(): bool {
        $process = proc_open(
            ['sudo', '-n', '/usr/local/sbin/backupmanager-test-connection'],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    public function restoreInventory(): JSONResponse {
        $process = proc_open(
            ["sudo", "-n", "-u", "backupmgr", "/usr/local/sbin/backupmanager-list-backups"],
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return new JSONResponse([
                "success" => false,
                "error" => "Restore inventory helper could not be started",
            ], 500);
        }

        $output = (string)stream_get_contents($pipes[1]);
        $error = trim((string)stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return new JSONResponse([
                "success" => false,
                "error" => $error !== "" ? $error : "Restore inventory unavailable",
            ], 500);
        }

        $section = "";
        $data = [];
        $database = [];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            $line = trim($line);

            if ($line === "DATA") {
                $section = "data";
                continue;
            }

            if ($line === "DATABASE") {
                $section = "database";
                continue;
            }

            if ($line === "") {
                continue;
            }

            if ($section === "data") {
                $data[] = $line;
            } elseif ($section === "database") {
                $database[] = $line;
            }
        }

        return new JSONResponse([
            "success" => true,
            "data" => $data,
            "database" => $database,
        ]);
    }



    public function restoreExecute(
        string $type = "",
        string $databaseBackup = "",
        string $confirm = ""
    ): JSONResponse {
        if ($confirm !== "RESTORE") {
            return new JSONResponse([
                "success" => false,
                "error" => "Restore confirmation required",
            ], 400);
        }

        if (!in_array($type, ["data", "database", "complete"], true)) {
            return new JSONResponse([
                "success" => false,
                "error" => "Invalid restore type",
            ], 400);
        }

        if (
            in_array($type, ["database", "complete"], true)
            && preg_match(
                '/^nextcloud-db-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.sql\.gz$/',
                $databaseBackup
            ) !== 1
        ) {
            return new JSONResponse([
                "success" => false,
                "error" => "Invalid database backup",
            ], 400);
        }

        if ($type === "data") {
            $command = [
                "sudo",
                "-n",
                "/usr/local/sbin/backupmanager-restore-data",
                "restore",
            ];
        } elseif ($type === "database") {
            $command = [
                "sudo",
                "-n",
                "/usr/local/sbin/backupmanager-restore-database",
                "restore",
                $databaseBackup,
            ];
        } else {
            $command = [
                "sudo",
                "-n",
                "/usr/local/sbin/backupmanager-restore-complete",
                $databaseBackup,
            ];
        }

        $process = proc_open(
            $command,
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return new JSONResponse([
                "success" => false,
                "error" => "Restore process could not be started",
            ], 500);
        }

        $output = trim((string)stream_get_contents($pipes[1]));
        $error = trim((string)stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return new JSONResponse([
                "success" => false,
                "error" => $error !== "" ? $error : "Restore failed",
                "output" => $output,
            ], 500);
        }

        return new JSONResponse([
            "success" => true,
            "type" => $type,
            "databaseBackup" => $databaseBackup,
            "output" => $output,
        ]);
    }



    public function disasterRecovery(
        string $mode = "",
        string $databaseBackup = "",
        string $confirm = ""
    ): JSONResponse {
        if (!in_array($mode, ["verify", "restore"], true)) {
            return new JSONResponse([
                "success" => false,
                "error" => "Invalid disaster recovery mode",
            ], 400);
        }

        if (
            preg_match(
                '/^nextcloud-db-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.sql\.gz$/',
                $databaseBackup
            ) !== 1
        ) {
            return new JSONResponse([
                "success" => false,
                "error" => "Invalid database backup",
            ], 400);
        }

        if ($mode === "restore" && $confirm !== "RESTORE") {
            return new JSONResponse([
                "success" => false,
                "error" => "Disaster recovery confirmation required",
            ], 400);
        }

        $command = [
            "sudo",
            "-n",
            "/usr/local/sbin/backupmanager-disaster-recovery",
            $mode,
            $databaseBackup,
        ];

        $process = proc_open(
            $command,
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return new JSONResponse([
                "success" => false,
                "error" => "Disaster recovery process could not be started",
            ], 500);
        }

        $output = trim((string)stream_get_contents($pipes[1]));
        $error = trim((string)stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return new JSONResponse([
                "success" => false,
                "error" => $error !== "" ? $error : "Disaster recovery failed",
                "output" => $output,
            ], 500);
        }

        return new JSONResponse([
            "success" => true,
            "mode" => $mode,
            "databaseBackup" => $databaseBackup,
            "output" => $output,
        ]);
    }



    public function requestRecovery(
        string $clientId = ""
    ): JSONResponse {
        $providerUrl = rtrim(
            $this->config->getAppValue("backupstatus", "provider_url", ""),
            "/"
        );

        if ($providerUrl === "") {
            return new JSONResponse([
                "success" => false,
                "error" => "No provider URL configured",
            ], 400);
        }

        $clientId = trim($clientId);

        if ($clientId === "") {
            $clientId = $this->config->getAppValue(
                "backupstatus",
                "provider_client_id",
                ""
            );
        }

        if (preg_match('/^BM-[0-9]{6}$/', $clientId) !== 1) {
            return new JSONResponse([
                "success" => false,
                "error" => "Valid Backup Manager client ID required",
            ], 400);
        }

        $process = proc_open(
            ["sudo", "-n", "/usr/local/sbin/backupmanager-recovery-request-info"],
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return new JSONResponse([
                "success" => false,
                "error" => "Recovery helper could not be started",
            ], 500);
        }

        $output = trim((string)stream_get_contents($pipes[1]));
        $error = trim((string)stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return new JSONResponse([
                "success" => false,
                "error" => $error !== "" ? $error : "Recovery information unavailable",
            ], 500);
        }

        $parts = explode("\t", $output, 2);

        if (count($parts) !== 2) {
            return new JSONResponse([
                "success" => false,
                "error" => "Invalid recovery information",
            ], 500);
        }

        [$sourceId, $publicKey] = $parts;

        $payload = [
            "client_id" => $clientId,
            "source_id" => $sourceId,
            "public_key" => $publicKey,
        ];

        try {
            $client = $this->clientService->newClient();

            $response = $client->post(
                $providerUrl . "/api/v1/recovery-requests",
                [
                    "headers" => [
                        "Accept" => "application/json",
                        "Content-Type" => "application/json",
                    ],
                    "body" => json_encode(
                        $payload,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                    "timeout" => 30,
                ]
            );

            $data = json_decode((string)$response->getBody(), true);

            if (
                !is_array($data)
                || !($data["success"] ?? false)
                || empty($data["recovery_request_id"])
                || empty($data["request_token"])
            ) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Invalid recovery response from backup provider",
                ], 502);
            }

            $this->config->setAppValue(
                "backupstatus",
                "recovery_request_id",
                (string)$data["recovery_request_id"]
            );

            $this->config->setAppValue(
                "backupstatus",
                "recovery_request_token",
                (string)$data["request_token"]
            );

            $this->config->setAppValue(
                "backupstatus",
                "recovery_request_status",
                (string)($data["status"] ?? "pending")
            );

            $this->config->setAppValue(
                "backupstatus",
                "recovery_client_id",
                $clientId
            );

            return new JSONResponse([
                "success" => true,
                "requestId" => $data["recovery_request_id"],
                "status" => $data["status"] ?? "pending",
                "clientId" => $clientId,
            ]);

        } catch (Throwable $e) {
            return new JSONResponse([
                "success" => false,
                "error" => "Recovery request failed: " . $e->getMessage(),
            ], 502);
        }
    }



    public function recoveryStatus(): JSONResponse {
        $providerUrl = rtrim(
            $this->config->getAppValue("backupstatus", "provider_url", ""),
            "/"
        );

        $requestId = $this->config->getAppValue(
            "backupstatus",
            "recovery_request_id",
            ""
        );

        $requestToken = $this->config->getAppValue(
            "backupstatus",
            "recovery_request_token",
            ""
        );

        if ($providerUrl === "" || $requestId === "" || $requestToken === "") {
            return new JSONResponse([
                "success" => false,
                "error" => "No recovery request available",
            ], 404);
        }

        try {
            $client = $this->clientService->newClient();

            $response = $client->get(
                $providerUrl . "/api/v1/recovery-requests/" . rawurlencode($requestId),
                [
                    "headers" => [
                        "Accept" => "application/json",
                        "Authorization" => "Bearer " . $requestToken,
                    ],
                    "timeout" => 30,
                ]
            );

            $data = json_decode((string)$response->getBody(), true);

            if (!is_array($data) || !($data["success"] ?? false)) {
                return new JSONResponse([
                    "success" => false,
                    "error" => "Invalid recovery status response from backup provider",
                ], 502);
            }

            $status = (string)($data["status"] ?? "unknown");

            $this->config->setAppValue(
                "backupstatus",
                "recovery_request_status",
                $status
            );

            if ($status === "approved") {
                $alreadyActivated = $this->config->getAppValue(
                    "backupstatus",
                    "recovery_key_activated",
                    "0"
                ) === "1";

                if (!$alreadyActivated) {
                    $command =
                        'sudo -n /usr/local/sbin/backupmanager-activate-recovery-key';

                    exec($command, $output, $exitCode);

                    if ($exitCode !== 0) {
                        return new JSONResponse([
                            "success" => false,
                            "error" => "Recovery approved, but replacement key activation failed",
                        ], 500);
                    }

                    $this->config->setAppValue(
                        "backupstatus",
                        "recovery_key_activated",
                        "1"
                    );
                }

                if (isset($data["connection"]) && is_array($data["connection"])) {
                    $connection = $data["connection"];

                    $clientId = (string)($connection["client_id"] ?? "");
                    $host = (string)($connection["host"] ?? "");
                    $port = (string)($connection["port"] ?? "22");
                    $user = (string)($connection["user"] ?? "");
                    $path = (string)($connection["path"] ?? "/");

                    $command = sprintf(
                        'sudo /usr/local/sbin/backupmanager-apply-provider-config %s %s %s %s %s',
                        escapeshellarg($clientId),
                        escapeshellarg($host),
                        escapeshellarg($port),
                        escapeshellarg($user),
                        escapeshellarg($path)
                    );

                    exec($command, $configOutput, $configExitCode);

                    if ($configExitCode !== 0) {
                        return new JSONResponse([
                            "success" => false,
                            "error" => "Recovery approved, but provider configuration failed",
                        ], 500);
                    }

                    $this->config->setAppValue(
                        "backupstatus",
                        "provider_client_id",
                        $clientId
                    );
                }
            }

            return new JSONResponse([
                "success" => true,
                "status" => $status,
                "requestId" => $requestId,
                "connection" => $data["connection"] ?? null,
                "activated" => $this->config->getAppValue(
                    "backupstatus",
                    "recovery_key_activated",
                    "0"
                ) === "1",
            ]);

        } catch (Throwable $e) {
            return new JSONResponse([
                "success" => false,
                "error" => "Recovery status check failed: " . $e->getMessage(),
            ], 502);
        }
    }


}
