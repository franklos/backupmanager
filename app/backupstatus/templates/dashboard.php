<?php
/** @var array $_ */
style('backupstatus', 'dashboard');

$storage = $_['storage'] ?? null;
$data = $_['dataSummary'] ?? [];
$database = $_['databaseSummary'] ?? [];

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1099511627776) {
        return number_format($bytes / 1099511627776, 1, ',', '.') . ' TB';
    }

    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }

    return number_format($bytes / 1024, 1, ',', '.') . ' KB';
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'ok' => 'OK',
        'failed' => 'Mislukt',
        default => 'Onbekend',
    };
};

$capacity = is_array($storage) ? (int)($storage['capacityBytes'] ?? 0) : 0;
$used = is_array($storage) ? (int)($storage['usedBytes'] ?? 0) : 0;
$free = is_array($storage) ? (int)($storage['freeBytes'] ?? 0) : 0;
$usedPercent = $capacity > 0 ? ($used / $capacity) * 100 : 0;
?>

<div class="backupstatus-dashboard">
    <h2>Backup Manager</h2>

    <?php if (!empty($_['clientId'])): ?>
        <p class="backupstatus-client">
            Backup client: <strong><?php p($_['clientId']); ?></strong>
        </p>
    <?php endif; ?>

    <div class="backupstatus-status">
        <div>
            <span>Status backup-opslag:</span>
            <strong>
                <?php p(is_array($storage) ? 'Verbonden' : 'Niet bereikbaar'); ?>
            </strong>
        </div>

        <div>
            <span>Opslagcapaciteit:</span>
            <strong><?php p($capacity > 0 ? $formatBytes($capacity) : '-'); ?></strong>
        </div>

        <div>
            <span>Gebruikte ruimte:</span>
            <strong>
                <?php
                p(
                    $capacity > 0
                        ? $formatBytes($used) . ' (' . number_format($usedPercent, 1, ',', '.') . '%)'
                        : '-'
                );
                ?>
            </strong>
        </div>

        <div>
            <span>Vrije ruimte:</span>
            <strong><?php p($capacity > 0 ? $formatBytes($free) : '-'); ?></strong>
        </div>

        <div class="backupstatus-separator"></div>

        <div>
            <span>Huidige data-back-up status:</span>
            <strong class="backupstatus-<?php p($data['status'] ?? 'unknown'); ?>">
                <?php p($statusLabel((string)($data['status'] ?? 'unknown'))); ?>
            </strong>
        </div>

        <div>
            <span>Laatste geslaagde data-back-up:</span>
            <strong><?php p($data['lastSuccess'] ?: '-'); ?></strong>
        </div>

        <div class="backupstatus-separator"></div>

        <div>
            <span>Database-back-up status:</span>
            <strong class="backupstatus-<?php p($database['status'] ?? 'unknown'); ?>">
                <?php p($statusLabel((string)($database['status'] ?? 'unknown'))); ?>
            </strong>
        </div>

        <div>
            <span>Laatste database-dump:</span>
            <strong><?php p($_['latestDatabaseDump'] ?: '-'); ?></strong>
        </div>

        <div>
            <span>Laatste geslaagde database-back-up:</span>
            <strong><?php p($database['lastSuccess'] ?: '-'); ?></strong>
        </div>
    </div>

    <p class="backupstatus-automatic">
        Back-ups verlopen geheel automatisch.
    </p>

    <p class="backupstatus-checked">
        Bijgewerkt:
        <?php p(date('d-m-Y H:i:s', (int)$_['checkedAt'])); ?>
    </p>
</div>
