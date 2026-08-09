<?php

declare(strict_types=1);

namespace OCA\BackupStatus\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\HintException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Throwable;

final class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IL10N $l10n,
        private IMailer $mailer,
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
            "client_version" => "0.1.0-beta2",
            "nextcloud_version" => implode(".", \OC_Util::getVersion()),
            "approval_email" => $providerEmail,
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
                ]
            );

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
}
