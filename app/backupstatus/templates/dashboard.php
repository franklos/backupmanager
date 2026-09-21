<?php
/** @var array $_ */
style('backupstatus', 'dashboard');
$details = $_['details'] ?? [];
?>
<div class="backupstatus-dashboard">
<h2><?php p($l->t('Backup Manager')); ?></h2>
<p><strong><?php p($l->t($_['label'])); ?></strong></p>
<dl>
<dt><?php p($l->t('Last successful backup')); ?></dt><dd><?php p($details['last_success'] ?? '—'); ?></dd>
<dt><?php p($l->t('Recovery point')); ?></dt><dd><?php p($details['generation'] ?? '—'); ?></dd>
<dt><?php p($l->t('Last attempt')); ?></dt><dd><?php p($details['last_attempt'] ?? '—'); ?></dd>
</dl>
<?php if (!empty($details['error'])): ?><p role="alert"><?php p($details['error']); ?></p><?php endif; ?>
</div>
