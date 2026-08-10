<?php

declare(strict_types=1);

namespace OCA\BackupStatus\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

final class AdminSettings implements ISettings {
    public function __construct(private IConfig $config) {
    }

    public function getForm(): TemplateResponse {
        return new TemplateResponse('backupstatus', 'admin', [
            'url' => $this->config->getAppValue(
                'backupstatus',
                'backup_url',
                ''
            ),
            'connected' => $this->config->getAppValue(
                'backupstatus',
                'connected',
                '0'
            ) === '1',
            'days' => array_filter(explode(
                ',',
                $this->config->getAppValue(
                    'backupstatus',
                    'backup_days',
                    'Mon,Tue,Wed,Thu,Fri,Sat,Sun'
                )
            )),
            'time' => $this->config->getAppValue(
                'backupstatus',
                'backup_time',
                '02:00'
            ),

            'destinationType' => $this->config->getAppValue(
                'backupstatus',
                'destination_type',
                'ssh'
            ),
            'credentialMode' => $this->config->getAppValue(
                'backupstatus',
                'credential_mode',
                'managed'
            ),

            'providerUrl' => $this->config->getAppValue(
                'backupstatus',
                'provider_url',
                ''
            ),
            'providerEmail' => $this->config->getAppValue(
                'backupstatus',
                'provider_email',
                ''
            ),
            'providerConsent' => $this->config->getAppValue(
                'backupstatus',
                'provider_consent',
                '0'
            ) === '1',
            'providerRequestSent' => $this->config->getAppValue(
                'backupstatus',
                'provider_request_sent',
                '0'
            ) === '1',
            'providerRequestStatus' => $this->config->getAppValue(
                'backupstatus',
                'provider_request_status',
                ''
            ),
            'providerClientId' => $this->config->getAppValue(
                'backupstatus',
                'provider_client_id',
                ''
            ),
        ]);
    }

    public function getSection(): string {
        return 'backupstatus';
    }

    public function getPriority(): int {
        return 10;
    }
}
