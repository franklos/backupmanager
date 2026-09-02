<?php
/** @var array $_ */

script('backupstatus', 'admin');
style('backupstatus', 'admin');

$weekDays = [
    'Mon' => $l->t('Monday short'),
    'Tue' => $l->t('Tuesday short'),
    'Wed' => $l->t('Wednesday short'),
    'Thu' => $l->t('Thursday short'),
    'Fri' => $l->t('Friday short'),
    'Sat' => $l->t('Saturday short'),
    'Sun' => $l->t('Sunday short'),
];
?>

<div id="backupstatus-admin" class="section">
    <h2><?php p($l->t('Backup Manager')); ?></h2>

    <div class="backupmanager-card">
        <h3><?php p($l->t('Backup destination')); ?></h3>

        <div class="backupstatus-field">
            <label for="backupstatus-destination-type">
                <?php p($l->t('Destination type')); ?>
            </label>

            <select id="backupstatus-destination-type">
                <option
                    value="ssh"
                    <?php if ($_['destinationType'] === 'ssh') { print_unescaped('selected'); } ?>>
                    <?php p($l->t('SSH server')); ?>
                </option>

                <option
                    value="aws_s3"
                    <?php if ($_['destinationType'] === 'aws_s3') { print_unescaped('selected'); } ?>>
                    <?php p($l->t('AWS S3')); ?>
                </option>

                <option
                    value="s3_compatible"
                    <?php if ($_['destinationType'] === 's3_compatible') { print_unescaped('selected'); } ?>>
                    <?php p($l->t('S3-compatible storage')); ?>
                </option>
            </select>
        </div>

        <div id="backupstatus-ssh-fields">
            <div class="backupstatus-field">
                <label for="backupstatus-credential-mode">
                    <?php p($l->t('SSH credentials')); ?>
                </label>

                <select id="backupstatus-credential-mode">
                    <option
                        value="manual"
                        <?php if ($_['credentialMode'] === 'manual') { print_unescaped('selected'); } ?>>
                        <?php p($l->t('Configure manually')); ?>
                    </option>

                    <option
                        value="managed"
                        <?php if ($_['credentialMode'] === 'managed') { print_unescaped('selected'); } ?>>
                        <?php p($l->t('Managed backup provider')); ?>
                    </option>
                </select>
            </div>

            <div id="backupstatus-ssh-manual-fields">
                <div class="backupstatus-field">
                    <label for="backupstatus-url">
                        <?php p($l->t('Backup server URL')); ?>
                    </label>

                    <input
                        id="backupstatus-url"
                        type="url"
                        value="<?php p($_['url']); ?>"
                        placeholder="https://backup.example.com/"
                        autocomplete="off">
                </div>
            </div>

            <div id="backupstatus-ssh-managed-fields">
                <input
                    id="backupstatus-use-provider"
                    type="checkbox"
                    hidden>

                <div class="backupstatus-field">
                    <label for="backupstatus-provider-url">
                        <?php p($l->t('Backup provider URL')); ?>
                    </label>

                    <input
                        id="backupstatus-provider-url"
                        type="url"
                        value="<?php p($_['providerUrl']); ?>"
                        placeholder="https://backup.example.com"
                        autocomplete="off">
                </div>

                <div class="backupstatus-field">
                    <label for="backupstatus-provider-email">
                        <?php p($l->t('Approval email')); ?>
                    </label>

                    <input
                        id="backupstatus-provider-email"
                        type="email"
                        value="<?php p($_['providerEmail']); ?>"
                        placeholder="backup@example.com"
                        autocomplete="off">
                </div>

                <label class="backupstatus-checkbox">
                    <input
                        id="backupstatus-provider-consent"
                        type="checkbox"
                        <?php if ($_['providerConsent']) { print_unescaped('checked'); } ?>>

                    <span>
                        <?php p($l->t('I consent to sending this request to the backup provider')); ?>
                    </span>
                </label>

                <div class="backupstatus-provider-actions">
                    <button
                        id="backupstatus-request-provider"
                        type="button"
                        <?php if ($_['providerRequestSent'] ?? false) { print_unescaped('disabled'); } ?>>
                        <?php p(($_['providerRequestSent'] ?? false)
                            ? $l->t('Request sent')
                            : $l->t('Request access')); ?>
                    </button>

                    <button
                        id="backupstatus-resend-provider"
                        type="button"
                        <?php if (!($_['providerRequestSent'] ?? false)) { print_unescaped('hidden'); } ?>>
                        <?php p($l->t('Resend notification')); ?>
                    </button>
                </div>
            </div>
        </div>

        <div id="backupstatus-aws-fields">
            <p><?php p($l->t('AWS S3 configuration will be added later.')); ?></p>
        </div>

        <div id="backupstatus-s3-fields">
            <p><?php p($l->t('S3-compatible storage configuration will be added later.')); ?></p>
        </div>
    </div>

    <div class="backupmanager-card">
        <h3><?php p($l->t('Backup schedule')); ?></h3>

        <fieldset id="backupstatus-schedule">
            <legend><?php p($l->t('Backup days')); ?></legend>

            <div class="backupstatus-week">
                <?php foreach ($weekDays as $value => $label): ?>
                    <label class="backupstatus-day">
                        <input
                            type="checkbox"
                            name="backupstatus-day"
                            value="<?php p($value); ?>"
                            <?php if (in_array($value, $_['days'], true)) { print_unescaped('checked'); } ?>>

                        <span><?php p($label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="backupstatus-field">
            <label for="backupstatus-time">
                <?php p($l->t('Start time')); ?>
            </label>

            <div class="backupstatus-time-wrapper">
                <input
                    id="backupstatus-time"
                    type="time"
                    value="<?php p($_['time']); ?>">
            </div>
        </div>
    </div>

    <div class="backupstatus-actions">
        <button
            id="backupstatus-save"
            type="button"
            class="primary">
            <?php p($l->t('Save and test')); ?>
        </button>

        <span
            id="backupstatus-result"
            class="<?php p($_['connected'] ? 'success' : 'failed'); ?>">
            <?php p($_['connected']
                ? $l->t('Connected')
                : $l->t('Connection failed')); ?>
        </span>
    </div>

    <div class="backupmanager-card backupstatus-recovery">
        <h3><?php p($l->t('Recovery')); ?></h3>

        <p>
            <?php p($l->t('Recovery options for this Nextcloud installation.')); ?>
        </p>

        <div class="backupstatus-recovery-grid">
            <div class="backupstatus-recovery-option">
                <h4><?php p($l->t('Restore backup')); ?></h4>
                <p>
                    <?php p($l->t(
                        'Restore data, the database, or the complete Nextcloud installation from an existing backup.'
                    )); ?>
                </p>
                <button id="backupstatus-recovery-restore" type="button" disabled>
                    <?php p($l->t('Restore backup')); ?>
                </button>

            <div id="backupstatus-restore-panel" class="backupstatus-restore-panel" hidden>
                <h4><?php p($l->t('Restore backup')); ?></h4>

                <label class="backupstatus-restore-choice">
                    <input type="radio" name="backupstatus-restore-type" value="data">
                    <span>
                        <strong><?php p($l->t('Restore data')); ?></strong>
                        <small><?php p($l->t('Restore the latest available data snapshot.')); ?></small>
                    </span>
                </label>

                <label class="backupstatus-restore-choice">
                    <input type="radio" name="backupstatus-restore-type" value="database">
                    <span>
                        <strong><?php p($l->t('Restore database')); ?></strong>
                        <small><?php p($l->t('Restore a selected database backup.')); ?></small>
                    </span>
                </label>

                <label class="backupstatus-restore-choice">
                    <input type="radio" name="backupstatus-restore-type" value="complete">
                    <span>
                        <strong><?php p($l->t('Restore complete installation')); ?></strong>
                        <small><?php p($l->t('Restore data and a selected database backup.')); ?></small>
                    </span>
                </label>

                <div id="backupstatus-restore-database-row" class="backupstatus-restore-database-row" hidden>
                    <label for="backupstatus-restore-database">
                        <?php p($l->t('Database backup')); ?>
                    </label>
                    <select id="backupstatus-restore-database"></select>
                </div>

                <div class="backupstatus-warning">
                    <?php p($l->t(
                        'A restore will overwrite existing data or database contents. No restore will start until you explicitly confirm it.'
                    )); ?>
                </div>

                <div class="backupstatus-actions">
                    <button id="backupstatus-restore-continue" type="button" class="primary" disabled>
                        <?php p($l->t('Start')); ?>
                    </button>

                    <button id="backupstatus-restore-cancel" type="button">
                        <?php p($l->t('Cancel')); ?>
                    </button>
                </div>
            </div>

            </div>

            <div class="backupstatus-recovery-option">
                <h4><?php p($l->t('Disaster recovery')); ?></h4>
                <p>
                    <?php p($l->t(
                        'Reconnect a newly installed server to an existing backup after loss or reinstallation of the original server.'
                    )); ?>
                </p>
                <button id="backupstatus-recovery-disaster" type="button" disabled>
                    <?php p($l->t('Start disaster recovery')); ?>
                </button>

                <div id="backupstatus-disaster-panel" class="backupstatus-restore-panel" hidden>
                    <h4><?php p($l->t('Disaster recovery')); ?></h4>

                    <div class="backupstatus-restore-database-row">
                        <label for="backupstatus-disaster-database">
                            <?php p($l->t('Database backup')); ?>
                        </label>
                        <select id="backupstatus-disaster-database"></select>
                    </div>

                    <div class="backupstatus-warning">
                        <?php p($l->t(
                            'Disaster recovery restores the saved configuration, database and latest data backup to this Nextcloud installation.'
                        )); ?>
                    </div>

                    <div class="backupstatus-actions">
                        <button id="backupstatus-disaster-verify" type="button">
                            <?php p($l->t('Preflight')); ?>
                        </button>

                        <button id="backupstatus-disaster-start" type="button" class="primary" disabled>
                            <?php p($l->t('Start')); ?>
                        </button>

                        <button id="backupstatus-disaster-cancel" type="button">
                            <?php p($l->t('Cancel')); ?>
                        </button>
                    </div>
                </div>

            </div>

            <div class="backupstatus-recovery-option">
                <h4 id="backupstatus-recovery-access-title">
                    <?php p($l->t('Recover backup access')); ?>
                </h4>
                <p id="backupstatus-recovery-access-text">
                    <?php p($l->t(
                        'Restore access to an existing remote backup when local credentials or configuration have been lost.'
                    )); ?>
                </p>
                <button id="backupstatus-recovery-access" type="button" disabled>
                    <?php p($l->t('Recover access')); ?>
                </button>
            </div>
        </div>
    </div>

    <div class="backupmanager-card backupstatus-removal">
        <h3><?php p($l->t('Remove Backup Manager')); ?></h3>

        <p>
            <?php p($l->t(
                'Choose what should be removed. Remote backup data is never removed unless explicitly selected.'
            )); ?>
        </p>

        <label class="backupstatus-checkbox">
            <input id="backupstatus-remove-local" type="checkbox">
            <span>
                <?php p($l->t('Remove local Backup Manager configuration and credentials')); ?>
            </span>
        </label>

        <label class="backupstatus-checkbox">
            <input id="backupstatus-remove-remote" type="checkbox">
            <span id="backupstatus-remove-remote-label">
                <?php p($l->t('Remove remote backup data for this installation')); ?>
            </span>
        </label>

        <div id="backupstatus-remove-confirmation" hidden>
            <p class="backupstatus-warning">
                <?php p($l->t(
                    'Remote backup deletion is permanent and is limited to the storage assigned to this Backup Manager client.'
                )); ?>
            </p>

            <div class="backupstatus-field">
                <label for="backupstatus-remove-confirm-text">
                    <?php p($l->t('Type DELETE to confirm remote backup deletion')); ?>
                </label>
                <input
                    id="backupstatus-remove-confirm-text"
                    type="text"
                    autocomplete="off"
                    placeholder="DELETE">
            </div>
        </div>

        <button id="backupstatus-remove-button" type="button" class="warning" disabled>
            <?php p($l->t('Remove Backup Manager')); ?>
        </button>
    </div>
</div>
