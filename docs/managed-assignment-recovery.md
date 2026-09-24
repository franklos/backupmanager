# Managed assignment after recovery (0.2.4)

Recovery approval preserves the client's existing storage allocation. Previously,
`RequestController::recoveryStatus()` copied `storage_host`, port and user from
that allocation, including a legacy `localhost`, rather than from the current
canonical provider configuration. The Nextcloud controller and runtime helper
then faithfully saved that stale assignment. A successful connection or a pinned
host key for localhost did not establish that the canonical endpoint was in use.

There was also a browser race: both initial enrollment/recovery status polls
reloaded the entire settings form for every non-pending status, even when no
assignment had been applied. A late response could undo an unsaved choice of
Managed provider and restore the saved Manual mode. The manual host input is a
separate profile, so it could legitimately be blank; entering managed connection
information there is not the repair.

The provider now resolves public connection fields from its canonical
`storage.host`/`storage.port` and restricted `backupstore` account. Approval still
preserves allocation identity/path and approved keys. The approval polling
controller applies the connection, then stores the client identity and managed
mode in app settings. The runtime helper writes the active SSH assignment and
`storage_profiles.ssh_managed` together and preserves other profiles. Public
runtime serialization supplies those profiles to the UI, which displays managed
connection details without including editable SSH fields in managed saves.
Only a poll that actually applied an assignment requests a background settings
reload, and a reload cannot overwrite a form being edited.

An administrator can now use **Refresh provider assignment** (Dutch:
**Providertoewijzing vernieuwen**) for an already-activated client. The protected
Nextcloud POST endpoint `settings/refresh-provider` uses the existing client API
token to read `GET /api/v1/clients/BM-000007/connection`. The provider validates an
active client and SSH allocation and returns the current canonical endpoint.
The runtime helper's explicit `select_managed` operation accepts only the existing
client identity and prohibits recovery activation. This operation updates mode
and both runtime assignment copies without touching the approved key pair,
provider database, allocation, generations, host pins or system timer. Manual
mode/profile is preserved for later selection. No new request is created and an
approved recovery is not replayed.

## Minimal deployment and repair of the already-approved BM-000007

These steps are instructions only; they have not been executed on the live host.
They assume the installer paths: provider `/opt/backupmanager-provider`, runtime
`/usr/local/lib/backupmanager`, and app `/var/www/nextcloud/apps/backupstatus`.
Use the actual served app/provider paths if the installation has overridden them.
Do not run the installer, migrations or recovery approval again for this repair.

From the reviewed source tree on ncdev, install only the changed production files:

```sh
set -eu
for file in public/index.php src/StorageEndpoint.php src/Controller/RequestController.php; do
    sudo install -m 0644 "provider/$file" "/opt/backupmanager-provider/$file"
done
sudo install -m 0644 runtime/backupmanager/client_helpers.py /usr/local/lib/backupmanager/backupmanager/client_helpers.py
for file in appinfo/routes.php lib/Controller/SettingsController.php js/admin.js templates/admin.php l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json; do
    sudo install -m 0644 "app/backupstatus/$file" "/var/www/nextcloud/apps/backupstatus/$file"
done
sudo systemctl reload php8.3-fpm
```

The final command refreshes the PHP 8.3 FPM opcode cache used by ncdev; use the
serving FPM unit if a different PHP version is configured. Hard-reload Backupbeheer
so its new JavaScript and translations are loaded. In **Storage & scheduling**,
select **Beheerde provider**, then click **Providertoewijzing vernieuwen**. This
refresh also persists the managed selection; do not click Recover access or create
a new enrollment. No Save settings action is needed (and the timer is not changed).
The displayed assignment must be `BM-000007 — backupstore@backup.ncdev.local:22`.

Read-only verification on the client:

```sh
sudo /usr/local/sbin/backupmanager-control settings
```

Check `credential_mode` is `managed`, `destination` is `ssh`, `client_id` is
`BM-000007`, and both active `ssh_host` and
`storage_profiles.ssh_managed.ssh_host` are `backup.ncdev.local`, with port 22,
user `backupstore`, path `/`. The app settings are updated by the refresh endpoint;
no direct database/appconfig edits are needed. A pin scoped only to localhost does
not authenticate the new hostname. If needed, use the existing independently
verified provider host key in SSH host trust for the canonical name. The previous
connection-test result is invalidated by the endpoint change; test again after
host verification. Existing backup generations and approved client keys remain
unchanged.

## Regression coverage

- `tests/test_managed_recovery.py`: exact BM-000007 localhost refresh; both runtime
  copies; manual round trip; public serialization; key bytes, modes, inode and
  generation preservation; unchanged schedule; repeatability and identity guards.
- `tests/settings_status.php`: canonical response forwarding, managed app settings,
  failed application handling, and refresh without recovery activation.
- `tests/provider_database.php`: isolated MariaDB, canonical connection from an
  approved legacy recovery/allocation; authenticated refresh; no allocation/key
  mutation and denial with an invalid token.
- `tests/test_legacy_journey.py`: actual provider/router/controller/helper chain
  from localhost to backup.ncdev.local, then repair of that already-approved
  client using its existing token, followed by isolated real SSH/backup/restore.
- `tests/managed_recovery_browser.js`: Dutch and English browser regression for
  late status polls, managed selection/refresh/save, disabled SSH serialization,
  and separate manual settings. Included when `BM_BROWSER_TESTS=1` runs the suite.
