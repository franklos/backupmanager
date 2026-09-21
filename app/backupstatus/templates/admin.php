<?php
/** @var array $_ */
script('backupstatus', 'admin');
style('backupstatus', 'admin');
?>
<div id="backupstatus-admin" class="section" data-provider-pending="<?php p($_['providerPending'] ? '1' : '0'); ?>" data-recovery-pending="<?php p($_['recoveryPending'] ? '1' : '0'); ?>">
<h2><?php p($l->t('Backup Manager')); ?></h2>
<p id="bm-message" role="status" aria-live="polite"></p>
<form id="bm-settings">
<fieldset><legend><?php p($l->t('Storage and schedule')); ?></legend>
<label><?php p($l->t('Destination')); ?><select name="destination"><option value="ssh">SSH</option><option value="aws_s3">AWS S3</option><option value="s3_compatible">S3-compatible</option></select></label>
<label><?php p($l->t('SSH credentials')); ?><select name="credential_mode"><option value="managed">Managed provider</option><option value="manual">Manual</option></select></label>
<div id="bm-ssh-fields">
<label>SSH host<input name="ssh_host" autocomplete="off"></label>
<label>SSH port<input name="ssh_port" type="number" min="1" max="65535"></label>
<label>SSH user<input name="ssh_user" autocomplete="off"></label>
<label>SSH path<input name="ssh_path" autocomplete="off"></label>
</div>
<div id="bm-s3-fields" hidden>
<label>HTTPS S3 endpoint<input name="s3_endpoint" type="url"></label>
<label>S3 region<input name="s3_region"></label>
<label>S3 bucket<input name="s3_bucket"></label>
<label><?php p($l->t('Installation-specific prefix')); ?><input name="s3_prefix"></label>
<label><?php p($l->t('Server-side encryption')); ?><select name="s3_sse"><option value="">Bucket default</option><option value="AES256">AES256</option></select></label>
<label>S3 access key<input name="s3_access_key" type="password" autocomplete="new-password"></label>
<label>S3 secret key<input name="s3_secret_key" type="password" autocomplete="new-password"></label>
<label>S3 session token<input name="s3_session_token" type="password" autocomplete="new-password"></label>
<p><?php p($l->t('Leave credential fields blank to retain stored credentials. Credentials are never returned by the server.')); ?></p>
</div>
<label><?php p($l->t('Backup days')); ?><input name="days" placeholder="Mon,Tue,Wed,Thu,Fri,Sat,Sun" required></label>
<label><?php p($l->t('Start time')); ?><input name="time" type="time" required></label>
<label><?php p($l->t('Timezone')); ?><input name="timezone" required></label>
<label><?php p($l->t('Retention days')); ?><input name="retention_days" type="number" min="1" max="36500" required></label>
<label><?php p($l->t('Overdue threshold in hours')); ?><input name="stale_hours" type="number" min="1" max="8760" required></label>
<button type="submit"><?php p($l->t('Save settings')); ?></button>
<button type="button" id="bm-test"><?php p($l->t('Test connection')); ?></button>
<button type="button" id="bm-backup"><?php p($l->t('Back up now')); ?></button>
</fieldset></form>
<fieldset id="bm-trust"><legend><?php p($l->t('SSH host trust')); ?></legend>
<p><?php p($l->t('Obtain the host public key and fingerprint from your provider through a trusted channel. Save SSH settings before pinning the key.')); ?></p>
<label><?php p($l->t('Host public key')); ?><textarea id="bm-host-key"></textarea></label>
<label>SHA256 fingerprint<input id="bm-host-fingerprint"></label>
<button id="bm-pin" type="button"><?php p($l->t('Pin verified host key')); ?></button>
</fieldset>
<fieldset id="bm-provider"><legend><?php p($l->t('Provider enrollment and access recovery')); ?></legend>
<label>HTTPS provider URL<input id="bm-provider-url" type="url" value="<?php p($_['providerUrl']); ?>"></label>
<label><?php p($l->t('Provider notification email')); ?><input id="bm-provider-email" type="email" value="<?php p($_['providerEmail']); ?>"></label>
<label><input id="bm-consent" type="checkbox"><?php p($l->t('I consent to sending this request to the backup provider')); ?></label>
<button id="bm-enroll" type="button"><?php p($l->t('Request access')); ?></button>
<button id="bm-resend" type="button"><?php p($l->t('Resend notification')); ?></button>
<label><?php p($l->t('Existing client ID')); ?><input id="bm-client-id" value="<?php p($_['clientId']); ?>" placeholder="BM-000001"></label>
<button id="bm-recover" type="button"><?php p($l->t('Recover access')); ?></button>
<p id="bm-enrollment-status" role="status"></p>
</fieldset>
<fieldset><legend><?php p($l->t('Recovery points')); ?></legend>
<button id="bm-inventory" type="button"><?php p($l->t('Refresh recovery points')); ?></button>
<p id="bm-storage"></p>
<label><?php p($l->t('Recovery point')); ?><select id="bm-generation"></select></label>
<label><?php p($l->t('Restore type')); ?><select id="bm-restore-type"><option value="complete">Database and data</option><option value="data">Data only</option><option value="database">Database only</option><option value="disaster">Disaster recovery: configuration, database and data</option></select></label>
<p><?php p($l->t('Restore overwrites the selected components. A failed restore leaves maintenance enabled for operator review.')); ?></p>
<button id="bm-verify" type="button"><?php p($l->t('Verify recovery point')); ?></button>
<button id="bm-restore" type="button"><?php p($l->t('Start restore')); ?></button>
<button id="bm-cancel" type="button" disabled><?php p($l->t('Cancel before restore starts')); ?></button>
<p id="bm-job" role="status" aria-live="polite"></p>
</fieldset>
<fieldset><legend><?php p($l->t('Provider clients')); ?></legend><div id="backupstatus-clients"></div></fieldset>
<fieldset><legend><?php p($l->t('Remove Backup Manager')); ?></legend>
<label><input id="bm-remove-local" type="checkbox"><?php p($l->t('Remove local client and configuration')); ?></label>
<label><input id="bm-remove-remote" type="checkbox"><?php p($l->t('Delete this installation’s remote recovery points')); ?></label>
<label><?php p($l->t('Type DELETE to confirm remote deletion')); ?><input id="bm-delete-confirm" autocomplete="off"></label>
<button id="bm-remove" type="button"><?php p($l->t('Remove Backup Manager')); ?></button>
</fieldset>
</div>
