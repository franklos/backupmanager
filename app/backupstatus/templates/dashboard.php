<?php
/** @var array $_ */
style('backupstatus', 'dashboard');
script('backupstatus', 'dashboard');
$details = $_['details'] ?? [];
?>
<div id="backupstatus-dashboard" data-locale="<?php p($l->getLocaleCode()); ?>">
<h2><?php p($l->t('Backup Manager')); ?></h2>
<section class="bm-status-card" aria-labelledby="backupstatus-heading">
<h3 id="backupstatus-heading"><?php p($l->t($_['label'])); ?></h3>
<dl class="bm-metadata">
<?php foreach (['last_success' => 'Last successful backup', 'last_attempt' => 'Last attempt'] as $key => $label): ?>
<div class="bm-metadata-row">
<dt><?php p($l->t($label)); ?></dt>
<dd><?php if (!empty($details[$key])): ?>
<time datetime="<?php p($details[$key]); ?>" title="<?php p($details[$key]); ?>"><?php p($l->t('Unknown')); ?></time>
<?php else: ?><?php p($l->t('Unknown')); ?><?php endif; ?></dd>
</div>
<?php endforeach; ?>
</dl>
<?php if (!empty($details['error'])): ?>
<p class="bm-status-error" role="alert"><?php
    p($l->t(\OCA\BackupStatus\Service\ErrorMessages::message($details)));
?></p>
<?php endif; ?>
</section>
<?php if (!empty($details['generation']) || !empty($details['error_code']) || !empty($details['cleanup_error'])): ?>
<details class="bm-technical">
<summary><?php p($l->t('Technical details')); ?></summary>
<dl class="bm-metadata">
<?php if (!empty($details['generation'])): ?><div class="bm-metadata-row">
<dt><?php p($l->t('Recovery point')); ?></dt>
<dd><code><?php p($details['generation']); ?></code></dd>
</div><?php endif; ?>
<?php if (!empty($details['error_code'])): ?><div class="bm-metadata-row"><dt><?php p($l->t('Error code')); ?></dt><dd><code><?php p($details['error_code']); ?></code></dd></div><?php endif; ?>
<?php if (!empty($details['cleanup_error'])): ?><div class="bm-metadata-row"><dt><?php p($l->t('Cleanup')); ?></dt><dd><?php p($l->t('Operation cleanup failed. Review the installation.')); ?></dd></div><?php endif; ?>
</dl>
</details>
<?php endif; ?>
</div>
