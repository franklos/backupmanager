<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'status#check', 'url' => '/status', 'verb' => 'GET'],
        ['name' => 'page#dashboard', 'url' => '/dashboard', 'verb' => 'GET'],
        ['name' => 'settings#save', 'url' => '/settings', 'verb' => 'POST'],
        ['name' => 'settings#enableLocalRemote', 'url' => '/settings/enable-local-remote', 'verb' => 'POST'],
        ['name' => 'settings#requestProvider', 'url' => '/settings/request-provider', 'verb' => 'POST'],
        ['name' => 'settings#providerStatus', 'url' => '/settings/provider-status', 'verb' => 'GET'],
        ['name' => 'settings#managementClients', 'url' => '/settings/management-clients', 'verb' => 'GET'],
        ['name' => 'settings#managementClientAction', 'url' => '/settings/management-clients/{clientId}/{clientAction}', 'verb' => 'POST'],
        ['name' => 'settings#requestRecovery', 'url' => '/settings/request-recovery', 'verb' => 'POST'],
        ['name' => 'settings#recoveryStatus', 'url' => '/settings/recovery-status', 'verb' => 'GET'],
        ['name' => 'settings#restoreInventory', 'url' => '/settings/restore-inventory', 'verb' => 'GET'],
        ['name' => 'settings#restoreExecute', 'url' => '/settings/restore', 'verb' => 'POST'],
        ['name' => 'settings#disasterRecovery', 'url' => '/settings/disaster-recovery', 'verb' => 'POST'],
        ['name' => 'settings#resendProviderNotification', 'url' => '/settings/provider-resend', 'verb' => 'POST'],
        ['name' => 'settings#removeBackupManager', 'url' => '/settings/remove', 'verb' => 'POST'],
    ],
];
