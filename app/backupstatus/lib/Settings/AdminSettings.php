<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Settings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
final class AdminSettings implements ISettings {
    public function __construct(private IConfig $config) {}
    public function getForm(): TemplateResponse {
        return new TemplateResponse('backupstatus', 'admin', [
            'providerUrl' => $this->config->getAppValue('backupstatus', 'provider_url', ''),
            'providerEmail' => $this->config->getAppValue('backupstatus', 'provider_email', ''),
            'clientId' => $this->config->getAppValue('backupstatus', 'provider_client_id', ''),
            'providerPending' => $this->config->getAppValue('backupstatus', 'provider_request_id', '') !== '',
            'recoveryPending' => $this->config->getAppValue('backupstatus', 'recovery_request_id', '') !== '',
        ]);
    }
    public function getSection(): string { return 'backupstatus'; }
    public function getPriority(): int { return 10; }
}
