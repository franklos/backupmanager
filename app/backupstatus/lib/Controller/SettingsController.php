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

    public function requestProvider(string $consent = "0", string $providerUrl = "", string $providerEmail = ""): JSONResponse {
        if ($consent !== "1") {
            return new JSONResponse(["success" => false, "error" => $this->l10n->t("Consent is required")], 400);
        }

        $providerUrl = trim($providerUrl);
        $providerEmail = trim($providerEmail);

        if (filter_var($providerUrl, FILTER_VALIDATE_URL) === false || strtolower((string)parse_url($providerUrl, PHP_URL_SCHEME)) !== "https") {
            return new JSONResponse(["success" => false, "error" => $this->l10n->t("Enter a valid HTTPS provider URL")], 400);
        }

        if (filter_var($providerEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new JSONResponse(["success" => false, "error" => $this->l10n->t("Enter a valid approval email address")], 400);
        }

        $process = proc_open(["sudo", "-n", "/usr/local/sbin/backupmanager-request-info"], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        if (!is_resource($process)) {
            return new JSONResponse(["success" => false, "error" => "Request helper could not be started"], 500);
        }

        $output = trim((string)stream_get_contents($pipes[1]));
        $error = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return new JSONResponse(["success" => false, "error" => $error !== "" ? $error : "Request information unavailable"], 500);
        }

        $parts = explode("\t", $output, 3);
        if (count($parts) !== 3) {
            return new JSONResponse(["success" => false, "error" => "Invalid request information"], 500);
        }

        [$sourceId, $backupHost, $publicKey] = $parts;
        $requestId = "BM-" . gmdate("Ymd") . "-" . strtoupper(bin2hex(random_bytes(3)));
        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$providerEmail]);
            $message->setSubject("Nieuwe Backup Manager-aanvraag " . $requestId);
            $message->setPlainBody("Aanvraag-ID: " . $requestId . "\nBron: " . $sourceId . "\nDoelserver: " . $backupHost . "\n\nPublieke SSH-sleutel:\n" . $publicKey . "\n");
            $this->mailer->send($message);
        } catch (Throwable $e) {
            return new JSONResponse(["success" => false, "error" => "Mail verzenden mislukt: " . $e->getMessage()], 500);
        }

        $this->config->setAppValue("backupstatus", "provider_url", $providerUrl);
        $this->config->setAppValue("backupstatus", "provider_email", $providerEmail);
        $this->config->setAppValue("backupstatus", "use_external_provider", "1");
        $this->config->setAppValue("backupstatus", "provider_consent", "1");
        $this->config->setAppValue("backupstatus", "provider_request_sent", "1");
        $this->config->setAppValue("backupstatus", "provider_request_id", $requestId);
        $this->config->setAppValue("backupstatus", "provider_request_time", (string)time());

        return new JSONResponse(["success" => true, "requestId" => $requestId]);
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
