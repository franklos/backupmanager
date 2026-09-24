> Current provider-administration acceptance: see [the 0.2.4 report and ordered review-only procedure](provider-administration.md). The existing REC-20260923-F6F6A4 is pending; do not create a replacement request or follow historical approval steps below during deployment.

# ncdev / BM-000007: upgrade and validation

The operator confirmed that the preceding provider/client deployment succeeded and
both migration commands now return an up-to-date no-op with runtime CRUD grants.
The subsequent live Recover access click created no new row. The supplied row is
REC-20260921-550904 for BM-000007, pending, expired, and missing its read key.
The deployment/migration outcomes are operator-reported. The audit also read
Apache access logs, system journal entries and web/FPM configuration metadata;
it did not read credential values or change live state.

This follow-up audit changes repository files and private fixtures only. It performs
no deployment, live database/privilege/storage/key changes, backup, restore, commit
or push. Earlier working-tree changes remain intact.

## Recovery failure: evidence and fixes

The current live blocker is established by read-only log correlation:

| UTC, 2026-09-23 | Nextcloud request | Provider request | MariaDB journal |
|---|---|---|---|
| 07:10:37 | POST request-recovery → 400 | POST recovery-requests → 500 | Access denied for backupmanager_provider@localhost |
| 07:10:57–58 | POST request-recovery → 400 | POST recovery-requests → 500 | Access denied for backupmanager_provider@localhost |

The click **did** submit. The web provider could not authenticate to its database,
so it never reached recovery-row insertion. This is database authentication, not
missing DDL privileges and not the expired legacy row blocking submission.

The enabled Apache file `/etc/apache2/sites-enabled/backup-provider.conf` still has
`DocumentRoot /var/www/backupmanager-provider/public`. The installer deploys the
corrected provider to `/opt/backupmanager-provider`. The only configured PHP-FPM
pool is `www-data` on `/run/php/php8.3-fpm.sock`; there is no dedicated bmprovider
pool. The canonical provider configuration directory is private to the provider
group (mode 0750). CLI success under bmprovider therefore did not validate the
HTTP deployment, which used a different code tree and pool. Credential contents
were not inspected, so no password value or live credential repair is inferred.

The repository now supplies `apache.conf.example` alongside the existing dedicated
FPM example. `installer/install.py provider --check-web` is read-only and reports
all three mismatches. `provider --apply` installs files but returns exit 2 rather
than reporting deployment completion if the enabled web root/handler/pool still
point elsewhere. It does not rewrite or reload the operator's HTTPS configuration.
The isolated deployment regression reproduces these exact old bindings, applies
the example bindings in a temporary tree, and requires the check to pass. Apache
syntax is validated without starting a server. The continuous upgrade journey
includes this configuration transition before recovery.

The expired write-only row itself does **not** block the corrected provider: the
continuous fixture recreates it and submits a fresh row through real admin JS,
route mapping, SettingsController, RuntimeService, client helper, provider
entrypoint/router and RequestController. Source identity and the permanent client
ID are preserved; no reset, new enrollment or DB privilege change is needed.

A separate pre-HTTP failure was reproduced: a surviving staged private key with
mode 0644 is refused by `ssh-keygen -y`. The helper now repairs it to 0600, refuses
symlinks and validates distinct public keys. This is an additional tested fix, not
the root cause of the two observed live clicks, which reached HTTPS.

Additional fixes:

- Recovery sends and validates consent. Submission announces progress and returns
  the new request ID. A dedicated, localized message beside the recovery button
  survives background status refreshes; HTTP/non-JSON errors include safe technical
  context. Local runtime failures, HTTP failures, polls and provider failures log
  stable codes or safe exception/SQL metadata without tokens or request bodies.
- Browser value collection is inside error handling; blocked browser storage cannot
  prevent handler registration. Initialization failures are visible. Poll epochs
  prevent a pre-click response from replacing newer browser state.
- Pending/failed recovery takes precedence over an otherwise active client in the
  section indicator. Provider onboarding polling is suppressed while recovery is
  pending. A returned request ID is checked before activation.
- Recovery approval responses include the approved public key pair. Activation
  verifies that pair against the staged manifest before replacing either key;
  a failed activation retains the request and safely retries. Superseded approved
  recovery tokens no longer receive a usable connection response.
- PHP expiry comparisons explicitly use UTC, including approval commands, and
  expire at the boundary. Recovery retirement is scoped to the locked client rather
  than updating unrelated clients while holding that client's lock.
- Connection tests support safe cancellation, including checkpoints between
  transfers and cleanup of only their own probe. Finalization preserves cancellation
  and distinguishes it from interruption and authentication failure.
- App version is 0.2.4 to identify the changed app assets/protocol. Deploy provider,
  runtime helpers and app together; an old provider lacking the approved pair cannot
  safely activate the new client.

## Continuous isolated acceptance fixture

`tests/test_legacy_journey.py` runs one continuous journey, not a collection of
independently mocked success responses:

1. Start private MariaDB on a Unix socket, a certificate-verified HTTPS provider
   endpoint and an unprivileged SSH daemon bound only to loopback. All writable data, keys, authorization and restore targets are under
   a guarded `/tmp/bm-integration-*` root. No host services or live accounts change.
2. Seed BM-000007, its allocation, legacy mirror files, a coherent old generation,
   active write-only key, expired enrollment and expired write-only recovery. Keep
   the original Nextcloud/provider identity and external data directory.
3. Run the actual installer in staging mode twice, the real legacy parser/key
   upgrader and actual migration code. Preserve the original write key.
4. Exercise consent denial, stale state, fresh recovery, duplicate conflict and
   localized browser errors. The new row contains distinct real public keys. The
   provider's actual router/rate limiter/controller run against the private DB.
5. Approve through the real approval CLI and Provisioning class. The actual Python
   authorization helper installs one `rrsync -wo -munge` and one `-ro -munge` entry
   rooted at the same existing allocation. Reprovisioning is idempotent.
6. Reject mismatched staged keys without consuming the request; restore the staged
   key and retry activation. Retire old enrollment polling without changing ID.
7. Pin a real SSH host key. Prove that pinning alone does not establish connection-test
   success. Run actual queued/running/terminal probe jobs over both SSH keys, plus
   cancellation, interruption, authentication failure and stale-success invalidation.
8. Create a real config/SQL/data generation, discover both old and new generations,
   exclude legacy mirrors, and retain a real failed backup's SSH root cause through
   the finalizer and persisted status.
9. Remove write authorization in the fixture and restore using only the read key.
   Reject corrupted download contents before changing the target, then perform a
   disaster restore to another external datadir and a separate private SQL database.
   Verify restored bytes, effective configuration, database values and source safety.
10. Compare every original storage file byte-for-byte, the allocation inode and DB
    row, both generation IDs and the permanent identity. Assert no BM-000008 exists.

With `BM_BROWSER_TESTS=1`, Chromium clicks the actual rendered PHP admin template
and JavaScript, tests Dutch/English, non-JSON HTTP rejection, persistent conflict
messages, long fields, keyboard activation, and desktop/375/320px widths. The
separate dashboard browser suite covers 128 theme/width/language/status/length
combinations plus locale/error fallback and keyboard behavior.

Fixture boundaries: Nextcloud interfaces/config storage and OCC are test adapters; the provider
Config and Database classes are real and load a generated staged configuration.
A test-only path guard prevents discovery of the live /etc provider configuration;
Provider requests traverse a loopback HTTPS server with certificate verification
against a fixture-only CA, then execute the real provider entrypoint/router.
The Nextcloud HTTP-service adapter maps only the fixture hostname to loopback;
production DNS, Apache/FPM and Nextcloud HTTP middleware are not run by this fixture. The fixture checks the CSRF header but does
not claim to test Nextcloud's CSRF enforcement. Systemd startup is replaced with
explicit worker invocation. SSH, key authentication/restrictions, rsync, SQL,
archives, manifest verification and restore operations are real. Unix service-user
switching maps to the current unprivileged fixture account. Installer `--apply`
requires live acceptance; the fixture runs `--destdir` plus its real upgrade helpers.

## Next operator step after this audit

The next operator command is to install the reviewed provider files. It will
intentionally return **2** while the old Apache/PHP-FPM bindings remain; files are
installed, but HTTP deployment is incomplete. Do not use `&&` to hide subsequent
configuration work behind that expected preflight result:

```sh
cd /home/admin/backupmanager
sudo python3 installer/install.py provider --apply
```

In the same maintenance window, install the dedicated pool and edit the existing
HTTPS vhost (the inspected site is a regular file at the following path):

```sh
sudo install -o root -g root -m 0644 provider/config/php-fpm.conf.example /etc/php/8.3/fpm/pool.d/backupmanager-provider.conf
sudoedit /etc/apache2/sites-enabled/backup-provider.conf
```

Preserve its ServerName, certificates and logging. Replace its old DocumentRoot
and matching Directory block with the directives in
`provider/config/apache.conf.example`: document root
`/opt/backupmanager-provider/public`, front-controller fallback, and the PHP handler
`proxy:unix:/run/php/backupmanager-provider.sock|fcgi://localhost`. Do not merely
change DB credentials in the obsolete `/var/www` tree. Do not make the canonical
configuration readable by www-data or change the database's grants.

Validate before reloading; only proceed when each validation exits 0:

```sh
sudo php-fpm8.3 -t
sudo apache2ctl configtest
sudo systemctl reload php8.3-fpm
sudo systemctl reload apache2
python3 installer/install.py provider --check-web
sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php --check
sudo python3 installer/install.py client --apply --nextcloud-root /var/www/nextcloud
```

Complete any Nextcloud app-upgrade step required by version 0.2.4, reload with fresh
assets, retain BM-000007, check consent and Recover access once. The UI must show
the fresh REC ID or a persistent localized error with technical context. Approve
that two-key request and follow the host-trust, connection-test and backup steps
below. Keep the timer stopped until acceptance succeeds. No live restore is needed
for this update. The complete offline restore already uses a private database and
external temporary datadir.

Remaining live acceptance: verify Apache/FPM actually loaded the new bindings,
production HTTPS/Nextcloud middleware and cached assets, real service-account
permissions and the actual provider's connection/backup results. The read-only
web check validates configuration files, not the running server's loaded state.

## Findings and fixes

- The reported generic migration failure came from unconditional baseline
  `CREATE TABLE IF NOT EXISTS` statements. Existing tables do not exempt these
  statements from CREATE privilege checks. The corrected migrator inspects first
  and returns successfully on an empty plan without DDL or DML, releasing its lock.
  CLI regressions invoke the no-argument command with a CRUD-only database account
  and compare all table definitions, rows and grants before and after. Pending
  CREATE, ALTER and INDEX failures identify the operation and required privilege.
  These migration tests use only a private MariaDB fixture.

  The next operator command from this reviewed checkout on the provider host is
  `sudo python3 installer/install.py provider --apply`. This installs corrected
  files without migrating the database. Resolve the web preflight described above
  if it exits 2. Then run
  `sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php --check`.
  If it exits 0, the original no-argument command must also exit 0 with
  “Schema and data migrations are up to date; no changes required.” Do not grant
  schema privileges merely to retry the old installed migrator.

- A service exit was previously converted unconditionally to “Operation interrupted”,
  overwriting an engine error. A connection-test cleanup could also replace the
  original transfer exception. The engine, worker and finalizer now retain a stable
  `error_code`, a safe primary message and application diagnostics where available.
  Cleanup errors and systemd exit context are separate fields. Terminal worker results
  are authoritative; a finalizer cannot change a recorded cancellation into a failure.
- Explicit cancellation markers identify operator cancellation. Signal exits identify
  interruption; SIGTERM, watchdog/OOM termination and service timeouts have their own
  causes/context. An ordinary nonzero exit without an earlier cause is a failure,
  not evidence of interruption. SIGTERM alone cannot establish who requested a stop.
  Even a systemd `success` result is checked for a signal-terminated worker, so a
  stopped Type=exec job cannot remain running indefinitely. A finalizer failure is logged separately and never substitutes its exception for
  the operation error.
- The historical ncdev log contains an SSH authentication rejection. It does not
  establish that the engine independently interrupted a job: the test run may have
  been stopped manually. Historical records are not rewritten to manufacture success.
- Legacy clients had a write-only key, whereas the current protocol requires separate
  restricted write/read keys. Installation preserves the existing private write key
  and creates the missing read key. Recovery submits a distinct staged pair and
  activates it only after a validated approval response. Public keys are derived from the authoritative private keys. The requested pair
  is validated before activation; a private manifest makes a partial activation
  safely retryable without mixing an old key into the approved pair. BM-000007 remains the permanent client ID.
- Expired pending requests and obsolete pending requests without a distinct read key
  no longer occupy the pending-request slot. Recovery submission is serialized per
  client. Successful recovery retires the obsolete local enrollment poll before using
  its new permanent API token. Superseded poll responses cannot consume a fresh
  request. A genuinely live pending request still needs approval/rejection; the UI
  explains that case rather than silently replacing it. Repeated approved recovery
  with the same staged pair is supported without duplicate active key records.
- Approval installs both restricted entries through the existing atomic
  `authorized_keys` helper. Recovery refuses a nonstandard existing allocation for
  operator review rather than silently pointing new keys at different storage.
  Existing generations and legacy data/database/config mirrors are retained.
- A pinned SSH host key was labelled “Ready”, even though it did not establish write
  and read authentication. The section now says **SSH host trust / Host key pinned**
  and directs the operator to the separate connection test. That test publishes
  queued/running/terminal states, persists results across reloads, and invalidates
  old results when configuration, keys, host pins or S3 credentials change. Cleanup
  affects only its unique temporary test generation. Backup status no longer
  overwrites the connection-test verdict.
- The dashboard uses responsive definition-list grid rows, wrapping values,
  Nextcloud theme tokens and native keyboard-operable technical details. Recovery
  IDs are secondary. Dates use the Nextcloud locale and browser timezone, retaining
  the exact timestamp in `datetime`/`title`. Missing/invalid dates have a localized
  fallback. Dashboard, header and admin backup status terminology is consistent.
  Primary errors come from a localized allowlist; technical causes remain in
  persisted state, server logs and admin technical details. Failure is never made
  green merely by changing its wording.

## Operator steps — perform after review, not during code preparation

On **ncdev**, from the reviewed checkout:

```sh
cd /home/admin/backupmanager
sudo systemctl stop backupmanager.timer
systemctl list-units --all 'backupmanager*'
```

Wait for active backup/restore jobs to finish. Do not stop a restore to make the
upgrade window convenient. Privately back up the provider database, client runtime
configuration, current private keys, Nextcloud Backup Manager app configuration and
provider authorization file before deploying. Include custom key paths recorded in
`/etc/backupmanager/runtime.json`. Keep the existing BM-000007 storage intact; do
not rerun a fresh database schema, remove the client, or create a replacement client.

1. **Provider host:** verify that BM-000007 still has its existing allocation
   `/var/lib/backupmanager-provider/BM-000007`, and retain its complete contents.
   Check existing provider configuration (including the client-reachable SSH host
   and port); the installer preserves it. From the reviewed checkout on that host:

   ```sh
   sudo python3 installer/install.py provider --apply
   sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php --check
   ```

   `--check` inspects without executing DDL or changing rows. Exit **0** means
   up to date: continue with step 2 using the existing CRUD-only database grants.
   Exit **2** lists each pending schema/data change and its required privilege.
   Exit **1** is a diagnostic failure: keep the deployment window open and resolve
   the reported privilege, duplicate-data or schema conflict before continuing.

   If only data changes are pending, run the same command without `--check` using
   `bmprovider`, then check again. If DDL is pending, follow **Provider migration
   preflight and credentials** in [upgrading.md](upgrading.md#provider-migration-preflight-and-credentials).
   Follow its exact temporary grant / migrate / revoke sequence: grant only the
   pending operation privileges to the separate deployment account, migrate, revoke
   its grants, drop that account and remove its private credential file. Clean up
   on failure too. Never add DDL privileges to the runtime account. After revocation,
   recheck as `bmprovider` and require exit 0 before proceeding. Keep the timer
   stopped until the later acceptance steps finish. Preserve BM-000007 and its
   storage throughout.

   If resuming the reported failed deployment: leave the timer stopped, deploy this
   corrected checkout again to replace the defective migrator, then run `--check`
   above. Provider file installation already succeeded; it did not migrate the DB.
   The old migrator attempted `CREATE TABLE IF NOT EXISTS` unconditionally, which
   requires CREATE even when the table exists. The generic error alone cannot
   establish whether other changes remain pending; use the corrected inspection.

   A nonstandard allocation or duplicate legacy record needs explicit reconciliation;
   do not delete data or change the permanent client ID to bypass the check. The
   provider SSH account home remains `/var/lib/backupmanager-provider-account`;
   legacy authorization entries are not blindly imported into that home.

2. **ncdev client:** deploy all runtime/helpers and the app together, so an old
   write-only helper cannot be paired with the new provider API:

   ```sh
   sudo python3 installer/install.py client --apply --nextcloud-root /var/www/nextcloud
   ```

   Use the existing installation root if it differs. Do not reset `runtime.json`,
   delete the existing write key, or remove the app's stored provider identity.

3. Open **Backup Manager → Provider and access**. Select managed SSH storage,
   retain the existing provider HTTPS URL and enter **BM-000007** under **Existing
   client ID**. Use **Recover access**, not a new-client enrollment. Check that a
   fresh request is recorded. Expired/obsolete pending requests are retired by the
   provider when a fresh request is submitted. If an unexpired usable request is
   already pending, approve that request or reject it explicitly in provider admin
   before retrying. Do not manually blank tokens in Nextcloud.

   Saving storage/schedule settings enables the timer. If a save is necessary
   before acceptance testing, stop `backupmanager.timer` again immediately.

4. In Nextcloud **Backupbeheer → Providerbeheer**, verify the request is for
   **BM-000007**, validate the requester and both public keys, then approve recovery.
   The emergency CLI alternative on the provider is:

   ```sh
   sudo -u bmprovider php /opt/backupmanager-provider/bin/approve-recovery.php REC-YYYYMMDD-XXXXXX operator
   ```

   Substitute the actual fresh request ID. Approval updates authorization for the
   same allocation; it does not recreate or clear the storage directory. Confirm
   both entries and their restrictions:

   ```sh
   sudo getent passwd backupstore
   sudo awk '/bm-(client|restore)=BM-000007$/ {print}' /var/lib/backupmanager-provider-account/.ssh/authorized_keys
   ```

   There must be one distinct write key with `rrsync -wo -munge` and one read key
   with `rrsync -ro -munge`, both rooted at the existing BM-000007 allocation.
   If an already approved pair needs reprovisioning after installation repair:

   ```sh
   sudo -u bmprovider php /opt/backupmanager-provider/bin/provision-ssh.php BM-000007
   ```

   This requires two approved keys. It is not a substitute for recovering a legacy
   record which contains only its old write key.

5. Keep the ncdev settings page open until approval polling succeeds. Confirm the
   client ID stays **BM-000007**, the host/port match the provider and the restricted
   client path is `/`. Polling validates the connection before activating the staged
   private keys and retaining the approval token. A failed application keeps the
   request available for retry.

6. Verify/pin the provider SSH host key using a trusted channel. “Host key pinned”
   means host trust, not authentication. Run **Test connection** and wait for the
   terminal **Completed / Connection tested successfully** result. It must upload,
   read back and clean up its own test object using the two key roles. Enqueue
   acceptance or a successful systemd helper exit alone is not a successful test.
   On failure, inspect the job's error and technical details and its journal:

   ```sh
   sudo journalctl -u 'backupmanager-job@*' -n 100 --no-pager
   ```

7. Refresh recovery points and confirm the existing generations are still present.
   Legacy mirror directories remain on storage but are not falsely advertised as
   coherent generation-based restore points. Run **Back up now**, wait for success,
   verify the new recovery point, and confirm the dashboard shows the localized
   successful timestamp. Do not manually clear historical errors to pass this step.
   Inspect scheduled-job diagnostics if needed:

   ```sh
   sudo journalctl -u backupmanager.service -n 100 --no-pager
   ```

8. Only after authentication, backup and verification succeed, restore the intended
   timer state:

   ```sh
   sudo systemctl enable --now backupmanager.timer
   systemctl list-timers backupmanager.timer
   ```

   Check the deployed page once in the actual Nextcloud theme in Dutch and English,
   at desktop and narrow widths. Plan a restore acceptance test on an isolated
   installation; do not overwrite the live ncdev instance for this UI upgrade.

## Validation results

Final standard suite: `bash tests/run.sh` completed with exit **0**; transcript:
`/tmp/bm-audit-final-confirmed.log`. Browser-enabled validation completed the full
73-test journey and the 128-case dashboard matrix in `/tmp/bm-audit-verified.log`.
That longer runner was terminated during its final syntax pass; the remaining
syntax checks were rerun successfully, followed by the clean full standard run.
The final secret-material scan and production fixture-identity scan were clean.


- 73 Python tests passed, including the private MariaDB migration/provider lifecycle,
  BM-000007 upgrade/recovery, real rsync/rrsync restrictions, 18 root-cause/finalizer
  regressions and five key-upgrade/activation tests. The continuous journey includes
  actual browser recovery, private SSH authentication and an isolated disaster restore.
- PHP provider authorization, request polling/recovery, runtime failure and error
  localization tests passed.
- JavaScript provider/host/storage status and Dutch/English job lifecycle/retry tests
  passed.
- 128 Chromium layout cases passed, plus locale/date/error fallbacks, native keyboard
  behavior, focus styling and contrast checks. English desktop (1280px) and Dutch
  narrow (375px) screenshots were also visually inspected.
- PHP/JavaScript/shell/Python syntax checks and `git diff --check` passed.

The complete suite was run with the optional browser tests enabled. Playwright,
Chromium and missing browser libraries were installed/extracted only under `/tmp`;
no system packages or live Backup Manager services were changed for those tests.

## Reproducing automated validation

```sh
bash tests/run.sh
```

This includes Python lifecycle/upgrade tests, isolated MariaDB migration and
provider API/approval tests, actual rsync/rrsync restriction tests, PHP/JS status
regressions, localization checks and syntax/diff checks. It does not use a live
provider database or perform a deployment.

The real-browser suite uses the actual dashboard PHP template, stylesheet and
JavaScript, with fixture Nextcloud localization and representative theme tokens /
conflicting global definition-list styles. It covers 128 layout cases: two languages,
four widths (1280/768/375/320), four backup states, long/normal values and light/dark
colors. It also checks invalid locale/date/error fallbacks, timestamp tooltips,
keyboard activation/focus, semantic label/value pairing and text contrast.

With a Node-compatible Playwright installation available:

```sh
BM_BROWSER_TESTS=1 bash tests/run.sh
```

For an isolated installation outside the checkout, set `NODE_PATH` to its
`node_modules` and `PLAYWRIGHT_BROWSERS_PATH` to its browser directory.
`BM_CHROMIUM_EXECUTABLE` optionally selects an installed Chromium executable.
Browser screenshots are written to `/tmp/bm-dashboard-en-1280.png` and
`/tmp/bm-dashboard-nl-375.png`.

These checks do not certify a deployed Nextcloud theme, a real screen reader,
current live provider credentials, or a production disaster restore. Those require
operator acceptance after deployment. No result from a historical manually stopped
job is used to claim autonomous engine interruption.

## Changed files in the delivered working tree

This complete inventory includes earlier in-progress changes preserved and tested
with the recovery audit. Production identity literals occur only in documentation
and isolated fixtures, not in production app/provider/runtime/installer code.

- `app/backupstatus/appinfo/info.xml`
- `app/backupstatus/css/admin.css`
- `app/backupstatus/css/dashboard.css`
- `app/backupstatus/js/admin.js`
- `app/backupstatus/js/dashboard.js`
- `app/backupstatus/js/menu.js`
- `app/backupstatus/l10n/en.js`
- `app/backupstatus/l10n/en.json`
- `app/backupstatus/l10n/nl.js`
- `app/backupstatus/l10n/nl.json`
- `app/backupstatus/lib/Controller/SettingsController.php`
- `app/backupstatus/lib/Service/ErrorMessages.php`
- `app/backupstatus/lib/Service/RuntimeService.php`
- `app/backupstatus/templates/admin.php`
- `app/backupstatus/templates/dashboard.php`
- `docs/ncdev-upgrade.md`
- `docs/operations.md`
- `docs/s3.md`
- `docs/upgrading.md`
- `installer/install.py`
- `installer/web_check.py`
- `provider/bin/approve-recovery.php`
- `provider/bin/approve.php`
- `provider/bin/migrate.php`
- `provider/config/apache.conf.example`
- `provider/config/config.php.example`
- `provider/public/index.php`
- `provider/src/Controller/RequestController.php`
- `provider/src/MigrationFailure.php`
- `provider/src/Migrations.php`
- `provider/src/Response.php`
- `runtime/backupmanager/client_helpers.py`
- `runtime/backupmanager/config.py`
- `runtime/backupmanager/control.py`
- `runtime/backupmanager/engine.py`
- `runtime/backupmanager/errors.py`
- `runtime/backupmanager/finalize.py`
- `runtime/backupmanager/phpconfig.py`
- `runtime/backupmanager/storage.py`
- `service/sbin/backupmanager-finalize`
- `tests/admin_fixture.php`
- `tests/dashboard_browser.js`
- `tests/dashboard_fixture.php`
- `tests/host_status.js`
- `tests/job_status.js`
- `tests/journey_boundary.php`
- `tests/journey_controller.php`
- `tests/journey_provider.php`
- `tests/journey_runtime.py`
- `tests/provider_database.php`
- `tests/provider_fixture_api.php`
- `tests/provider_fixture_bootstrap.php`
- `tests/provider_migration_bootstrap.php`
- `tests/provider_migrations.php`
- `tests/provider_status.js`
- `tests/recovery_browser.js`
- `tests/run.sh`
- `tests/runtime_service.php`
- `tests/settings_status.php`
- `tests/status_messages.php`
- `tests/storage_visibility.js`
- `tests/test_database.py`
- `tests/test_failures.py`
- `tests/test_host_verification.py`
- `tests/test_legacy_journey.py`
- `tests/test_runtime.py`
- `tests/test_storage_config.py`
- `tests/test_upgrade_keys.py`
- `tests/test_web_deployment.py`

## Migration deployment regression validation

The corrected migration is tested in a private MariaDB instance with a database
account granted only SELECT, INSERT, UPDATE and DELETE. Tests cover no-op check/apply,
missing tables/columns/indexes, exact operation and privilege diagnostics, duplicate
legacy data, conflicting column/index definitions (including differences inside
generated-expression string literals), lock release on failure,
retry after partial progress, fresh installation, equivalent legacy indexes, CLI exit
codes, separate deployment credentials and redacted SQL errors. These
checks do not deploy to ncdev or modify BM-000007 storage.


### PHP-FPM portability follow-up

The PHP 8.3 paths and commands above describe ncdev specifically. Its now-installed
bmprovider pool has passed `php-fpm8.3 -t` and can remain exactly as installed.
Do not copy ncdev's versioned paths to another host. For freshpcweb's PHP 8.4 or
other supported installations, use the [generic FPM discovery procedure](operations.md#selecting-the-provider-php-fpm-service).
`--detect-fpm` and `--check-web` are read-only and preserve the existing provider
pool's version when multiple PHP-FPM versions are installed. Portability fixtures
cover both versions, ambiguous and uniquely active multi-version installations,
existing-pool preservation, stale pools, duplicate listeners and missing FPM.

### False HTTP 200 investigation (2026-09-23)

Read-only inspection found `proxy` enabled but **`proxy_fcgi` absent** from
Apache's enabled modules. The configured Unix-socket handler consequently did not
execute PHP. Read-only GETs to `/index.php` and an unknown `/api/v1/` path returned
HTTP 200, no Content-Type, and exactly the 3,190 bytes of the installed public
`index.php` source. This was not an admin/login page or an API response. The
Apache `combined` format here uses `%O` (bytes sent including headers); the logged
4,980 bytes must not be interpreted as the response body length.

The isolated Apache/FPM regression reproduces that source response with the old
example and missing module. Loading `proxy_fcgi` preserves REQUEST_URI, query
parameters, method and POST body through FallbackResource; the existing router
then dispatches correctly. Neither PATH_INFO nor a route rewrite is required.
The corrected example denies requests when `proxy_fcgi` is absent, and
`--check-web` now checks the enabled module configuration as well as the pool.

The installed client already rejected non-JSON bodies, but used a generic failure
and retained the obsolete pending ID. Its diagnostics go to PHP's error log, not
necessarily Nextcloud's JSON log. The revised client requires JSON Content-Type,
a boolean success envelope and recovery ID/token fields, persists an actionable
`provider_invalid_response` error and retires obsolete polling IDs before a new
submission. A confirmed JSON request may survive a genuine JSON 409 duplicate
response; HTML/source/invalid-JSON responses never preserve it. Existing permanent
client identity and provider storage are unaffected.

After deploying the reviewed repository changes through the normal operator
procedure, the minimal **configuration** correction on ncdev is:

```sh
sudo a2enmod proxy proxy_fcgi
sudo apache2ctl configtest
sudo systemctl reload apache2
python3 installer/install.py provider --check-web
```

Keep the existing PHP 8.3 pool exactly as installed; no FPM pool edit, privilege
change or migration is needed for this routing correction. Also merge the new
fail-closed guard from `provider/config/apache.conf.example` into the existing
provider Directory block, preserving TLS and admin access controls. Validate
Apache before reloading. Do not replace the entire VirtualHost with the snippet.
Require an unknown API GET to return JSON HTTP 404 before retrying Recover access.
A normal provider HTTP error is meaningful; HTTP 200 containing PHP source is not.

Nextcloud's convenience HTTP methods pass lowercase verbs to Guzzle. Backup
Manager now uses Nextcloud's `request()` interface with uppercase methods. The
`CURLOPT_HTTP_VERSION` default originates in Nextcloud's HTTP Client constructor,
not Backup Manager; this change does not patch Nextcloud or disable its TLS,
proxy or local-address protections.

All investigation actions on ncdev were read-only. No Apache/FPM module was
enabled or reloaded by the agent, and no recovery POST, database modification,
key change, deployment, commit or push was performed against live ncdev.
