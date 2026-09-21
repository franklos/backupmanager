<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'status#check', 'url' => '/status', 'verb' => 'GET'],
        ['name' => 'page#dashboard', 'url' => '/dashboard', 'verb' => 'GET'],
        ['name' => 'settings#runtimeSettings', 'url' => '/settings/runtime', 'verb' => 'GET'],
        ['name' => 'settings#runtimeStatus', 'url' => '/settings/runtime-status', 'verb' => 'GET'],
        ['name' => 'settings#trust', 'url' => '/settings/trust', 'verb' => 'POST'],
        ['name' => 'settings#job', 'url' => '/settings/job', 'verb' => 'POST'],
        ['name' => 'settings#cancel', 'url' => '/settings/cancel', 'verb' => 'POST'],
        ['name' => 'settings#runBackup', 'url' => '/settings/backup', 'verb' => 'POST'],
        ['name' => 'settings#testConnection', 'url' => '/settings/test', 'verb' => 'POST'],
        ['name' => 'settings#save', 'url' => '/settings', 'verb' => 'POST'],
        ['name' => 'settings#requestProvider', 'url' => '/settings/request-provider', 'verb' => 'POST'],
        ['name' => 'settings#providerStatus', 'url' => '/settings/provider-status', 'verb' => 'POST'],
        ['name' => 'settings#managementClients', 'url' => '/settings/management-clients', 'verb' => 'GET'],
        ['name' => 'settings#managementClientAction', 'url' => '/settings/management-clients/{clientId}/{clientAction}', 'verb' => 'POST'],
        ['name' => 'settings#requestRecovery', 'url' => '/settings/request-recovery', 'verb' => 'POST'],
        ['name' => 'settings#recoveryStatus', 'url' => '/settings/recovery-status', 'verb' => 'POST'],
        ['name' => 'settings#restoreInventory', 'url' => '/settings/restore-inventory', 'verb' => 'GET'],
        ['name' => 'settings#restoreExecute', 'url' => '/settings/restore', 'verb' => 'POST'],
        ['name' => 'settings#disasterRecovery', 'url' => '/settings/disaster-recovery', 'verb' => 'POST'],
        ['name' => 'settings#resendProviderNotification', 'url' => '/settings/provider-resend', 'verb' => 'POST'],
        ['name' => 'settings#removeBackupManager', 'url' => '/settings/remove', 'verb' => 'POST'],
    ],
];
