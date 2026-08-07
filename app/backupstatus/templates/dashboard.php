<?php
/** @var array $_ */
style('backupstatus', 'dashboard');
$tasks = ['Data-back-up' => $_['data'], 'Database-back-up' => $_['database']];
?>
<div class="backupstatus-dashboard">
    <div class="backupstatus-dashboard__header">
        <h2>Back-upstatus</h2>
        <span class="backupstatus-overall backupstatus-<?php p($_['state']); ?>"><?php p($_['label']); ?></span>
    </div>
    <?php foreach ($tasks as $name => $segments): ?>
        <section class="backupstatus-task">
            <h3><?php p($name); ?></h3>
            <div class="backupstatus-bar" role="list" aria-label="<?php p($name); ?>">
                <?php foreach ($segments as $segment): ?>
                    <div class="backupstatus-segment-wrap">
                        <div class="backupstatus-segment backupstatus-<?php p($segment['state']); ?>" role="listitem" title="<?php p($segment['detail']); ?>" aria-label="<?php p($segment['label'] . ': ' . $segment['detail']); ?>"></div>
                        <small><?php p(substr((string)$segment['date'], 0, 5)); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <p class="backupstatus-checked">Bijgewerkt: <?php p(date('d-m-Y H:i:s', (int)$_['checkedAt'])); ?></p>
</div>
