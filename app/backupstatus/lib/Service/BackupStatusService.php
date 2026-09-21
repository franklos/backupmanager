<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Service;
final class BackupStatusService {
    public function __construct(private RuntimeService $runtime) {}
    public function getStatus(): array {
        $result = $this->runtime->call('status');
        $status = $result['status'] ?? ['state' => 'unavailable'];
        $state = (string)($status['state'] ?? 'missing');
        $label = match ($state) {
            'ok' => 'Backup current', 'restored' => 'Restore completed',
            'running' => 'Backup running', 'restoring' => 'Restore running',
            'stale' => 'Backup overdue', 'missing' => 'No successful backup',
            'maintenance_required' => 'Operator intervention required',
            'failed' => 'Backup failed', default => 'Status unavailable',
        };
        return ['state' => in_array($state, ['ok', 'restored'], true) ? 'ok' : ($state === 'unavailable' ? 'offline' : 'issue'),
            'label' => $label, 'details' => $status, 'checkedAt' => time()];
    }
}
