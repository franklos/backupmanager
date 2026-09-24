# Provider administration and ncdev acceptance report

This report covers the provider-administration changes on top of the existing
working tree. Nothing in this task was deployed, committed or pushed.

## Findings and verification limits

- **Canonical configuration:** the installed `Config` implementation prioritizes
  `/etc/backupmanager-provider/config.php`. The missing
  `/opt/backupmanager-provider/config/config.php` is not an installation failure.
  The old arguments misleadingly suggested that fallback path. All production
  entry points now use `Config()` with the canonical external default; there is
  no automatic fallback to a beside-code configuration. Explicit constructor
  injection is reserved for isolated tests/tools.
- **Storage error:** the old enrollment approval validates `storage.host` against
  a hostname expression and requires `storage.port` to be a PHP integer in
  1–65535, defaulting to 22 only if absent. An empty/invalid host or a quoted port
  therefore produces the reported error *before checking request expiry*.
  Recovery previously omitted endpoint validation altogether. Both paths now
  validate consistently after expiry checks, accept numeric port strings, and
  report `storage_not_configured` with an actionable message. Recovery also
  validates its existing allocation's endpoint and never changes that allocation.
- The actual protected live host/port values **could not be read in this session**:
  directory ownership is `root:bmprovider`, mode 0750; non-interactive sudo reports
  that a password is required. Consequently the exact offending live value is
  unverified. This report does not claim it was definitely an empty host or
  definitely a quoted port. The installer deliberately preserves existing config
  byte-for-byte and cannot safely guess a public SSH hostname; no config migration
  populates this value automatically.
- On the current ncdev client host, `backup.ncdev.local` resolves to the local
  provider host and SSH listens on port 22. **`backup.ncdev.local:22` is the
  appropriate named endpoint for this co-hosted acceptance fixture**, subject to
  checking the existing allocation and host trust. It is not a production default:
  on other clients/provider deployments the name must resolve to a reachable
  provider address, not their own loopback. Do not substitute `localhost`.
- **Expired enrollment:** the old admin SELECT queried stored `status="pending"`
  without checking `expires_at`; the row remained actionable until another path
  updated its status. The shared inventory now computes effective expiry in SQL
  using UTC without modifying rows. Expired history has no approval/rejection
  controls, and CLI/API actions independently reject expired requests.
- **Missing recovery:** the inspected installed admin file matched the repository
  and already queried/rendered pending recoveries in a separate lower section.
  The storage error did not suppress that query. Access logs confirm the new
  recovery POST returned 201 at 09:31:32 UTC, before the authenticated admin GET
  at 09:45:08 and POST at 09:45:15. No authenticated HTML or live DB read was
  available to prove why that particular page did not show the row; do not treat
  cache, scrolling, a different response or a different DB as established causes.
  The replacement interface uses one shared inventory, visibly separates pending
  enrollment/recovery from history, and is tested to render the pending recovery
  even while storage configuration is invalid.
- The double login comes from the live Apache `<Location "/admin/">` Basic Auth
  block plus the application's existing secure administrator session. The former
  is redundant. The intended example/checker now requires the single application
  login. No live Apache configuration was edited.

## Implemented workflow and security

Nextcloud **Backupbeheer → Providerbeheer** presents enrollments and recovery
requests, IDs, source/contact information, UTC timestamps, both public-key
fingerprints, pending/history states and explicit approve/reject actions.
The UI is localized in Dutch and English, wraps long values and works on narrow
screens and with keyboard controls. Request failures remain visible; a failed
refresh removes stale action buttons.

`ProviderAdminController` requires an authenticated Nextcloud administrator and a
valid CSRF token, in addition to retaining Nextcloud's default middleware. It
reads `/etc/backupmanager/management-token` server-side, sends HTTPS bearer calls
with redirects disabled, validates media type and response schemas, and returns
only an allowlist of request metadata. The browser receives a configured boolean,
never a token, password, DB credential or private key. The provider's management
routes require the existing management bearer token. The authenticated Nextcloud
UID is recorded as `nextcloud:<uid>` in approval/rejection events.

Standalone `/admin/` remains an emergency/maintenance interface with Dutch/English text and a language selector. It uses the
existing password-hash-backed session: HTTPS, secure/HTTP-only/SameSite cookies,
session regeneration, 30-minute expiry, password-rotation invalidation, CSRF,
login throttling and anti-framing headers. It shares the exact same request
inventory/actions as the management API. It does not require Apache Basic Auth.

Recovery approves only a `REC-...` row locked `FOR UPDATE`, serializes approvals,
checks active client identity and source ownership, requires the existing active
SSH allocation at its existing client path, and provisions distinct write/read
keys. A reviewed source change is allowed, but cannot take another client's source.
It neither allocates a new client ID nor creates missing recovery storage.
The existing request token hash becomes the active client token binding, so the
already-staged request/private-key pair can activate through normal polling;
no replacement recovery request is needed.

Credential replacement now journals the old authorization lines under the
root-owned provider account before atomically replacing them. A failed approval
rolls back those exact lines, including legacy write-only authorization. Success
finalizes the journal after DB commit; unrelated clients and storage files are
preserved. A process/system crash leaving `.credentials-BM-*.json` deliberately
blocks another replacement pending operator reconciliation. Do not delete such a
journal blindly: compare DB commit state and the active authorization first. The
CLI logs a specific reconciliation warning if post-commit journal cleanup fails.

App and provider release version: **0.2.4** (`appinfo/info.xml`, `provider/VERSION`).
No schema migration is introduced by this change.

## Validation

The browser-enabled full suite passed: **87 Python/integration tests**, PHP and
JavaScript regressions, **128 dashboard browser cases**, PHP/JS/Python/shell syntax
checks and `git diff --check`. The isolated management browser covers Dutch and
English, 1280/375/320 widths, keyboard approval, both fingerprints, history,
rejection without key changes, malformed HTML-200 responses and recovery approval.

The complete fixture runs:

legacy install → staged upgrade → fresh recovery → Nextcloud management browser
→ server-side controller → HTTPS provider management → real approval CLI → two-key
SSH provisioning → retryable client activation → host trust → connection test →
backup generation → discovery → isolated data/config/database restore.

It verifies unchanged allocation, preserved existing generations/files, permanent
BM-000007, no BM-000008, both rrsync restrictions, authenticated audit actor and
replayed-action rejection. Separate regressions cover administrator/CSRF denial,
credential non-disclosure, real standalone session login/rotation, source collision,
expired actions, missing/valid storage settings, canonical config preservation,
rollback to a legacy write-only authorization, and PHP-FPM 8.3/8.4 selection.
Real Apache/FPM fixtures retain fail-closed behavior and verify Authorization
forwarding to FPM. No fixture uses the live database or live storage.

## Review-only ncdev operator procedure

**Do not run this procedure until the report is reviewed. These commands were not
executed by the agent. Do not approve/reject a request as part of deployment.**

1. Inspect only the non-secret settings at the actual path:

   ```sh
   cd /home/admin/backupmanager
   sudo -u bmprovider php -r '$c=require "/etc/backupmanager-provider/config.php"; echo json_encode(["host"=>$c["storage"]["host"]??null,"port"=>$c["storage"]["port"]??null,"port_type"=>gettype($c["storage"]["port"]??null)]),PHP_EOL;'
   ```

   If needed, use `sudoedit /etc/backupmanager-provider/config.php` to set only
   `storage.host` to the client-reachable SSH name and `storage.port` to its SSH
   port. For the current co-hosted ncdev fixture the named endpoint is
   `backup.ncdev.local`, port `22`; preserve database settings, storage root/user,
   token/password paths and all other configuration. Do not rewrite allocations.

2. Remove only the redundant Basic Auth `<Location "/admin/">` block in
   `/etc/apache2/sites-enabled/backup-provider.conf`. Merge the fail-closed
   Directory/FilesMatch guards and Authorization forwarding from
   `provider/config/apache.conf.example`; preserve TLS, hostname, logs and the
   dedicated socket. **Keep the PHP 8.3 pool exactly as installed.**

   ```sh
   sudoedit /etc/apache2/sites-enabled/backup-provider.conf
   sudo apache2ctl configtest
   sudo systemctl reload apache2
   ```

   The existing application already supplies the secure session login. Do not
   replace it with unauthenticated administration or remove its Auth call.

3. Deploy the reviewed provider/client together and upgrade the Nextcloud app:

   ```sh
   sudo python3 installer/install.py provider --apply
   sudo python3 installer/install.py client --apply --nextcloud-root /var/www/nextcloud
   sudo -u www-data php /var/www/nextcloud/occ upgrade
   ```

   No `--migrate` is needed. Installer configuration preservation retains the
   pending recovery ID/token and private key pair. It does not enable the timer.

4. Reuse the existing management token; create one only if absent. On this
   co-hosted management installation, copy it server-side with restrictive mode:

   ```sh
   sudo test -s /etc/backupmanager-provider/management-token || sudo python3 /opt/backupmanager-provider/bin/create-management-token.py
   sudo install -o root -g www-data -m 0640 /etc/backupmanager-provider/management-token /etc/backupmanager/management-token
   ```

   These are the canonical token paths. If the existing provider config explicitly
   overrides `api.management_token_file`, retain that credential and securely
   copy from that configured path instead; do not rotate it accidentally. For
   separate hosts, use a private transfer; never paste the token into the browser.

5. Require read-only checks to pass:

   ```sh
   python3 installer/install.py provider --check-web
   sudo -u bmprovider php /opt/backupmanager-provider/bin/check-config.php --client BM-000007
   sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php --check
   sudo -u www-data php /var/www/nextcloud/occ status
   systemctl is-active backupmanager.timer
   ```

   The timer must report `inactive` (that command normally exits 3 when inactive).
   `check-config.php` prints only the configuration location, storage endpoint and
   selected allocation metadata, never DB credentials or tokens. A mismatch or
   invalid existing allocation needs operator review, not automatic reallocation.

6. Reload Nextcloud **Backupbeheer → Providerbeheer**. Review, but do not yet
   approve/reject, **REC-20260923-F6F6A4**, **BM-000007**, and both fingerprints.
   **REQ-20260921-B666A8** must be expired history without actions. Do not click
   Recover access again, reset request tokens, or approve the legacy recovery.
   Leave the timer stopped and wait for the next acceptance decision.

## Live safety

Only permitted read-only filesystem/log/service inspection and a failed
non-interactive sudo read were performed. No live request was approved/rejected,
no recovery was replaced, and no live DB, Apache/FPM configuration, authorized
keys, storage, generations or timer was modified. REC-20260923-F6F6A4 remains
untouched by this work; its last operator-confirmed state is pending. A fresh DB
read was unavailable to this session, so this is not a claim of an independent
post-task SQL verification.


## Exact files changed for this task

Earlier session changes in the working tree were preserved. This task changed
these 57 files (including new files):

- `app/backupstatus/appinfo/info.xml`
- `app/backupstatus/appinfo/routes.php`
- `app/backupstatus/css/admin.css`
- `app/backupstatus/js/admin.js`
- `app/backupstatus/l10n/en.js`
- `app/backupstatus/l10n/en.json`
- `app/backupstatus/l10n/nl.js`
- `app/backupstatus/l10n/nl.json`
- `app/backupstatus/lib/Controller/ProviderAdminController.php`
- `app/backupstatus/lib/Service/ErrorMessages.php`
- `app/backupstatus/templates/admin.php`
- `installer/install.py`
- `installer/web_check.py`
- `provider/VERSION`
- `provider/bin/approve.php`
- `provider/bin/approve-recovery.php`
- `provider/bin/approve-deletion.php`
- `provider/bin/reject-request.php`
- `provider/bin/reject-recovery.php`
- `provider/bin/reject-deletion.php`
- `provider/bin/migrate.php`
- `provider/bin/provision-ssh.php`
- `provider/bin/check-config.php`
- `provider/config/apache.conf.example`
- `provider/public/admin/index.php`
- `provider/public/index.php`
- `provider/src/Auth.php`
- `provider/src/AdminText.php`
- `provider/src/admin.nl.json`
- `provider/src/Config.php`
- `provider/src/Controller/ManagementController.php`
- `provider/src/RequestAdministration.php`
- `provider/src/StorageEndpoint.php`
- `provider/src/Provisioning.php`
- `runtime/backupmanager/provider_helpers.py`
- `tests/run.sh`
- `tests/provider_admin.php`
- `tests/provider_admin_session.php`
- `tests/test_provider_administration.py`
- `tests/provider_management_browser.js`
- `tests/journey_admin.php`
- `tests/journey_boundary.php`
- `tests/journey_controller.php`
- `tests/test_legacy_journey.py`
- `tests/recovery_browser.js`
- `tests/provider_database.php`
- `tests/provider_security.php`
- `tests/test_database.py`
- `tests/test_web_deployment.py`
- `tests/test_apache_routing.py`
- `docs/operations.md`
- `docs/upgrading.md`
- `docs/ncdev-upgrade.md`
- `docs/architecture.md`
- `docs/testing.md`
- `docs/security.md`
- `docs/provider-administration.md`
