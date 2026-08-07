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
                        <?php p($l->t('Request from server administrator')); ?>
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
                        <?php p($l->t('I consent to sending this request to the server administrator')); ?>
                    </span>
                </label>

                <div class="backupstatus-provider-actions">
                    <button
                        id="backupstatus-request-provider"
                        type="button"
                        <?php if ($_['providerRequestSent']) { print_unescaped('disabled'); } ?>>
                        <?php p($_['providerRequestSent']
                            ? $l->t('Request sent')
                            : $l->t('Request access')); ?>
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
</div>
