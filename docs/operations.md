# Installation and operations

The commands here are deployment instructions, not development test commands.
Review them on a staging machine before applying them to production.

## Prerequisites

Use Linux/systemd, Python 3.10+, PHP 8.2+ with PDO MySQL for the provider, Nextcloud 34,
OpenSSH, rsync with `/usr/bin/rrsync`, sudo/visudo, and MySQL/MariaDB client tools.
The client and provider installers check prerequisites but never install packages.
Provider storage must use a filesystem compatible with the selected rsync metadata.
The runtime snapshots regular local files; primary S3/external storage is outside
its scope. Allow staging space for a full compressed recovery point and, during
restore, an additional unpacked copy of data. Full backups are not deduplicated.

Nextcloud configuration must use literal `$CONFIG = array(...)` or `$CONFIG = [...]`
assignments. Function calls, includes, interpolation and constants are rejected.
Primary object-storage configurations are rejected to prevent a misleading local-only
backup from being reported as complete. Additional `*.config.php` literal arrays are merged in filename order into the
configuration snapshot. Dynamic configurations need an operator-managed literal
configuration before using this runtime. Configuration can contain passwords; do
not paste it into tickets or test fixtures.

## Client installation

From the reviewed checkout, as an explicit operator deployment:

```sh
sudo python3 installer/install.py client --apply --nextcloud-root /var/www/nextcloud
```

The installer copies the app, runtime, every client helper, units and exact sudo
rules. It creates `backupmgr` and separate Ed25519 write/read keys. It enables the
Nextcloud app but does not start the backup timer on a fresh install. Existing
configuration is preserved; legacy configuration is imported without sourcing it.

The authoritative configuration is `/etc/backupmanager/runtime.json` (root-only).
Paths and the Nextcloud service user are operator-controlled there. Storage,
schedule, retention and stale thresholds are editable through Backup Manager
settings. Saving settings applies and enables the timer. Configure/enroll storage,
pin SSH trust if applicable, test connectivity, and run a manual backup before
relying on the schedule. The status directory is traversable/readable by the web
server without granting it write access to runtime state or keys.

The supplied app integration assumes the Nextcloud web service account is
`www-data`. If yours differs, adapt the deployment sudo rules/group ownership and
`nc_user` before enabling the app. Do not grant unrestricted sudo access.

## Managed provider installation

Deploy the provider separately, preferably on a dedicated host:

```sh
sudo python3 installer/install.py provider --apply
```

This creates `bmprovider` for the API, `backupstore` for restricted SSH, copies the
provider into `/opt/backupmanager-provider`, and installs constrained root helpers.
It does not configure your web server, TLS certificate or database automatically.
Do not serve the repository: the web document root must be exactly
`/opt/backupmanager-provider/public`.

1. Create a dedicated MySQL/MariaDB database and runtime account with SELECT,
   INSERT, UPDATE and DELETE on that database only. Configure
   `/etc/backupmanager-provider/config.php` privately as `root:bmprovider`, mode
   `0640`. Keep the documented storage root and user; configure the externally
   reachable SSH hostname/port. Do not expose database ports publicly.
2. Run `sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php --check`.
   Exit 0 means no migration is required. Exit 2 lists pending operations and their
   required privileges without changing anything. Exit 1 reports a failure.
   For a fresh database or pending DDL, use the separate deployment-account workflow
   in [Provider migration preflight and credentials](upgrading.md#provider-migration-preflight-and-credentials).
   Keep runtime grants at SELECT, INSERT, UPDATE, DELETE throughout. Grant only the
   reviewed temporary privileges to the separate deployment account, apply migration,
   then revoke its grants, drop that account and remove its private credential file.
   Perform this cleanup on failure too. After revocation, require the runtime-account
   `--check` to exit 0 before enabling the API. An up-to-date schema needs no temporary
   account or DDL grants; data-only changes can use the runtime account.
   Migration preserves rows; duplicate legacy data and schema conflicts require
   explicit reconciliation instead of deleting client identity.
3. For the emergency standalone interface, run `sudo python3 /opt/backupmanager-provider/bin/set-admin-password.py`. It prompts
   privately, stores only a password hash, and does not print credentials.
4. For Apache, enable `proxy` and `proxy_fcgi` (`sudo a2enmod proxy proxy_fcgi`)
   and retain the supplied fail-closed module guard. Validate before reload; a
   running FPM pool alone does not establish PHP execution. Detect the installed PHP-FPM service using the [portable FPM procedure](#selecting-the-provider-php-fpm-service) below, then adapt the supplied
   `nginx.conf.example` (or `apache.conf.example`) and `php-fpm.conf.example` for
   your certificate and hostname. The FPM pool must run as `bmprovider` and use
   its private session directory. Only a trusted web server should set `HTTPS=on`.
5. OpenSSH must permit key authentication for `backupstore`, with its root-owned
   home `/var/lib/backupmanager-provider-account`. Password authentication must not
   be enabled for this account. Keep its authorization file root-owned. The helper
   supplies `restrict` and fixed read/write `rrsync` forced commands for every key.
6. Verify `/admin/` requires HTTPS and a password, and never expose legacy approval
   links. Configure network rate limiting as an additional edge control.

The fixed provider data root is `/var/lib/backupmanager-provider`. Existing data is
not deleted by installation or migration. For a provider-wide management panel in
Nextcloud, explicitly run `create-management-token.py` as root on the provider and
transfer the resulting file through a private channel to an authorized management
installation at `/etc/backupmanager/management-token`, `root:www-data`, `0640`.
Never provision that token to ordinary clients. Normal enrollment/recovery administration
is in Nextcloud **Backupbeheer → Providerbeheer**, using server-side authenticated
management calls. The standalone `/admin/` is for emergency/maintenance use and
uses its own secure administrator session. Do not add redundant Apache Basic Auth.
See [provider administration](provider-administration.md) for configuration, security,
approval semantics and the ordered ncdev deployment procedure.

## Enrollment and host trust

In Nextcloud settings select SSH and Managed provider, enter the HTTPS provider URL
and notification email, and consent to enrollment. The server sends both public
keys and the account email. Sign in to provider `/admin/`, verify identity through a
trusted channel, and approve. Polling applies the assigned host/user/client ID.

Obtain the SSH host public key and its SHA256 fingerprint directly from the
provider operator through a trusted channel. Enter both in **SSH host trust** after
the assigned connection settings are visible. No automatic key scanning or
accept-new bypass is used. If host keys rotate, verify the replacement out of band
and repin. The connection test uploads, reads back and removes a unique test object
using the actual restricted paths.

For manual SSH storage, configure an account and client directory on your storage
host with separate `restrict,command="/usr/bin/rrsync -wo -munge /path/to/client"` and
`restrict,command="/usr/bin/rrsync -ro -munge /path/to/client"` public-key entries. Install
only the client's public keys, never private keys. Pin the host key as above and
set the path as seen by the restricted account (normally `/`).

## Backups, status and retention

**Back up now** queues a job. Scheduled backups run from `backupmanager.timer`.
Normal backup jobs never toggle maintenance mode, regardless of backup size or
failure. They use the existing transactional database dump, local-data archive,
configuration capture and manifest-last upload. Nextcloud and Backup Manager status
polling stay available without a maintenance-mode interruption. If maintenance was
already enabled, the backup refuses to start and leaves it enabled.

This is an online capture, not an atomic database/configuration/data snapshot:
concurrent writes can produce differences between the database and file capture.
Prefer quiet periods, avoid configuration changes during capture, and arrange
writer coordination or an application-consistent snapshot separately when strict
cross-component consistency is required. Large backups use the same flow with
64 MiB upload chunks; they do not fall back to maintenance mode.

Check the dashboard for the last successful backup and overdue state, not just
connectivity. Set the overdue threshold to suit the longest schedule gap. A failed backup remains visible even if an older backup
was successful. The inventory displays committed artifact bytes for this client,
not the provider's entire filesystem usage or an invented quota.

Retention deletes committed points older than `retention_days`, always keeping the
newest successful point, and later cleans abandoned uploads. Local staging is
removed on normal completion/failure. Interrupted staging directories may remain
private under the runtime directory; after verifying no operation is running,
inspect and remove those manually. Legacy mirrors/dumps are never automatically
retained or deleted as if they were coherent generations.

### Deploying only the online-backup change

For an existing installation whose other runtime modules already match this
checkout, copy only the changed engine from the repository root. Wait until no
backup or restore is running before replacing it:

```sh
sudo install -o root -g root -m 0644 runtime/backupmanager/engine.py /usr/local/lib/backupmanager/backupmanager/engine.py
```

The next scheduled or manual job loads the updated module; no service restart,
installer, migration or Nextcloud maintenance command is needed. This command does
not change provider configuration, SSH keys, client IDs, storage allocations or
existing backup generations. Tests and documentation do not need to be deployed.

## Restore and disaster recovery

1. Stop other writers/Nextcloud cron work and check available disk space. Install
   the same Nextcloud version and required applications as the chosen point.
2. Configure the original storage location. For S3, supply valid credentials for
   the original bucket/prefix. For managed SSH on a replacement host, enter the
   provider URL and existing `BM-...` client ID under **Recover access**. Approve the
   replacement keys at the provider; both keys and the permanent API token rotate.
   Pin the provider host key again if necessary.
3. Refresh recovery points and **Verify recovery point**. Verification downloads
   and checks all chunks, gzip/tar/configuration, local version, DB connection and
   staging capacity. It does not execute the downloaded configuration.
4. Select data, database, both, or disaster recovery. Confirm the overwrite. Disaster
   recovery restores effective instance configuration while keeping this machine's
   DB connection/data location/trusted domains. It consolidates supplementary literal
   configuration into `config.php`; it does not reinstall Nextcloud binaries.
5. Monitor the persisted job. You may close/reopen the page; the last job ID is
   retained in browser storage. Cancellation is only safe before mutation. Repair
   and data-fingerprint refresh run before maintenance is disabled on success.

For a failed restore, do not simply disable maintenance. Inspect the job state and
local configuration, correct the cause, and decide whether to repeat the restore or
recover manually. The runtime refuses a new live restore while maintenance is
already enabled so an operator must explicitly reconcile the failed attempt first.
Never interpret a generic web timeout as successful restoration.

CLI compatibility restore commands now accept generation IDs. Individual legacy
backup commands create a complete recovery point, rather than incoherent separate
components. See upgrading.md for preserved legacy mirrors.

## Removal

Managed remote deletion creates a provider approval request. The provider revokes
both keys, records suspended intent before filesystem mutation, and supports retry
of failed deletion without marking the client healthy. Management **Remove** keeps
remote data; **Delete permanently** is allowed only for a terminated client.

Manual SSH/S3 removal queues deletion scoped to this installation's generation
namespace. Complete it before local removal; the UI refuses simultaneous local
purge that would discard an unfinished unmanaged deletion job. S3 versioning and
Object Lock rules are described in s3.md.

Client uninstall is explicit:

```sh
sudo python3 installer/uninstall.py --apply
sudo python3 installer/uninstall.py --apply --purge
```

The first disables/removes the client while retaining configuration/runtime state;
`--purge` also removes default local configuration/runtime/log directories. Neither
touches provider files, remote backups or Nextcloud data. A manifest prevents shared
provider runtime files from being removed on a combined host. Custom runtime paths,
legacy dump paths and service accounts are preserved for explicit operator cleanup.
Use the UI's local-removal action for a delayed systemd job that lets the response
finish before the app disappears.


## Storage configuration and source detection

Nextcloud is authoritative for the source data directory. Client installation reads
`datadirectory` from `config/config.php` and literal `*.config.php` overrides; external
paths such as `/data/ncdata` are supported. There is no editable source path in the
app. Backup and restore compare the stored `data_path` with this effective config
before changing live data. An upgrade also checks the match. After deliberately
moving Nextcloud data, the server administrator must reconcile the root-owned
`/etc/backupmanager/runtime.json` with Nextcloud before retrying. Missing, relative
or nonliteral configuration fails closed; do not substitute a guessed data path.
Staged installs do not inspect or configure a live Nextcloud installation.

Inactive form controls are disabled in the browser and ignored by the save API,
including SSH access mode under S3 and S3 credentials under SSH. Blank or stale
values from hidden controls cannot replace the stored settings.

Destination and SSH access mode determine which fields are shown and validated:

- Managed SSH: configure the HTTPS provider URL and request access. Approval supplies
  the client ID, SSH host, port, restricted `backupstore` user and allocation-relative
  path `/`. These connection values are read-only status information. The provider
  operator must configure a client-reachable SSH host before approving requests;
  the example has no localhost fallback. `/var/lib/backupmanager-provider/BM-…` stays
  provider-side, with the existing forced-command restrictions. Pin the SSH host key
  using a key and fingerprint obtained through a trusted channel, then verify it.
- Manual SSH: enter host, port, user and absolute destination path. Install both
  displayed public keys on the server with the appropriate write/read permissions.
  Private key paths are controlled by the server administrator in runtime.json;
  private keys are never returned to the browser. Pin and verify the host key.
- AWS S3: select a standard commercial AWS region; its endpoint is automatic.
- S3-compatible: supply the provider's HTTPS endpoint and signing region. See
  [S3 setup](s3.md) for bucket, prefix, encryption and credential requirements.

Each destination/access mode retains its own settings in the root-only
`storage_profiles` section of `runtime.json`. S3 credential-file references remain
private and are retained when switching destinations; the browser receives only
nonsecret settings. Returning to managed SSH restores the provider allocation,
and returning to manual SSH restores that manual connection. Existing installations
record their current settings on their next save or enrollment configuration update;
no provider storage migration is involved. Typed, unsaved credentials are cleared
when changing destinations. Recovery validates the connection and selected mode
before activating replacement keys. Host pins remain bound to host and port; a new
host must be verified through the existing trust controls.


### Disaster recovery with an external data directory

Each recovery point includes the effective Nextcloud configuration, including the
original absolute `datadirectory`, `instanceid`, `secret` and `passwordsalt`. Literal
`*.config.php` overrides are merged into that snapshot, so an external path is not
lost even when the base config uses another location. Treat the snapshot as secret.

Before recovery, install the matching Nextcloud version and configure its intended
data directory, for example `/mnt/storage/nextcloud-data`, in Nextcloud itself.
Install Backup Manager against that installation so `data_path` is detected from
Nextcloud. If already installed, the server administrator must reconcile its stored
path with the active Nextcloud configuration; a mismatch stops recovery.

Disaster restore restores the saved instance configuration, database and data while
preserving the destination installation's database connection and data directory.
The destination may use the original external path or a different external mount;
the runtime never substitutes `/var/www/nextcloud/data`. The target data directory
must already exist and be writable by the Nextcloud service user. Recovery does
not recreate mounts or install Nextcloud binaries.


## Selecting the provider PHP-FPM service

PHP CLI and PHP-FPM may have different versions. Do not derive the FPM pool path
or service name from `php -v`. On supported Debian/Ubuntu installations, run this
read-only discovery from the repository:

```sh
python3 installer/install.py provider --detect-fpm
```

The JSON result supplies `version`, `binary`, `service`, `pool` and `socket`.
Discovery requires the versioned FPM executable, systemd service unit and main
configuration including the pool directory. It preserves the installation that
already owns `/run/php/backupmanager-provider.sock`. Otherwise it selects the
sole active usable FPM service, or the sole usable installed version. Several
installed versions without a unique active service produce an actionable error;
explicitly select the intended version with `--php-fpm-version VERSION` after
checking `systemctl list-units --all 'php*-fpm.service'`. An explicit selection
cannot move an existing provider pool to another version. Duplicate provider
socket listeners must be reconciled first. Missing FPM never produces a guessed
path. Custom layouts outside these Debian/Ubuntu conventions require manual
configuration; the checker reports them as unsupported rather than guessing.

For a **new dedicated pool**, use the returned values. This Bash example stops
on failed discovery; add `--php-fpm-version VERSION` to the discovery command
when explicit selection is necessary:

```bash
set -euo pipefail
FPM_JSON="$(python3 installer/install.py provider --detect-fpm)"
FPM_POOL="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["pool"])' "$FPM_JSON")"
FPM_BINARY="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["binary"])' "$FPM_JSON")"
FPM_SERVICE="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["service"])' "$FPM_JSON")"
# Do not overwrite an existing, working pool: review/preserve it during upgrades.
test ! -e "$FPM_POOL"
sudo install -o root -g root -m 0644 provider/config/php-fpm.conf.example "$FPM_POOL"
sudo "$FPM_BINARY" -t
# After configuring and validating the provider web server's dedicated socket:
sudo systemctl reload "$FPM_SERVICE"
python3 installer/install.py provider --check-web
```

The pool and Apache/nginx examples use the same version-independent socket
`/run/php/backupmanager-provider.sock` and the dedicated `bmprovider` user/group.
The checker validates identity in the actual listener section and requires that
the selected FPM configuration includes that pool. It rejects stale installations
and duplicate listeners. It checks on-disk configuration, not a live handshake or
loaded worker state; require FPM/web-server syntax validation and HTTPS acceptance
after the operator reload. Neither discovery nor `--check-web` edits configuration
or reloads services. Provider `--apply` checks FPM selection before writing files
and continues to leave pool installation and service reload to the operator.


## Canonical provider configuration and approval preflight

All provider web and CLI entry points use `/etc/backupmanager-provider/config.php`
(`root:bmprovider`, 0640, containing directory 0750). Do not require a config file
under `/opt/backupmanager-provider/config/`; it is not the deployed configuration.
The installer preserves the external file and never guesses a public SSH hostname.
Set `storage.host` to the DNS name clients use for SSH and `storage.port` to the
SSH port. Well-formed numeric strings for ports are accepted for legacy configs.
Validate without changing data using `sudo -u bmprovider php
/opt/backupmanager-provider/bin/check-config.php --client BM-000000`, replacing the
client argument with an existing allocation (or omitting it for global config only).
Recovery uses and preserves the existing allocation, not a newly allocated path.
An invalid existing endpoint must be reviewed separately; editing defaults does
not silently rewrite allocation rows.

The management UI separates pending enrollment/recovery from expired/processed
history. Both API and CLI re-check expiry and identity before applying actions.
No administrator password/token/private SSH key is included in browser responses.
