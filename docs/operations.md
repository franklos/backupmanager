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

1. Create a dedicated MySQL/MariaDB database and account. Give the deployment account
   the schema privileges required for migration; runtime use needs CRUD on this
   database only. Configure `/etc/backupmanager-provider/config.php` privately as
   `root:bmprovider`, mode `0640`. Keep the documented storage root and user; configure
   the externally reachable SSH hostname/port. Do not expose database ports publicly.
2. Run `sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php`.
   Migration preserves rows, adds missing fields/indexes and backfills permanent
   client authentication. Repeating it is safe. Duplicate preexisting records stop
   migration for operator reconciliation rather than being deleted.
3. Run `sudo python3 /opt/backupmanager-provider/bin/set-admin-password.py`. It prompts
   privately, stores only a password hash, and does not print credentials.
4. Adapt the supplied `nginx.conf.example` and `php-fpm.conf.example` for your PHP
   version, certificate and hostname. The FPM pool must run as `bmprovider` and use
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
Never provision that token to ordinary clients. The standalone administration page
handles enrollment/recovery/deletion approvals without this token.

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
Backup jobs use maintenance mode to capture matching database/configuration/data,
then upload and commit the point. Check the dashboard for the last successful
backup and overdue state, not just connectivity. Set the overdue threshold to suit
the longest schedule gap. A failed backup remains visible even if an older backup
was successful. The inventory displays committed artifact bytes for this client,
not the provider's entire filesystem usage or an invented quota.

Retention deletes committed points older than `retention_days`, always keeping the
newest successful point, and later cleans abandoned uploads. Local staging is
removed on normal completion/failure. Interrupted staging directories may remain
private under the runtime directory; after verifying no operation is running,
inspect and remove those manually. Legacy mirrors/dumps are never automatically
retained or deleted as if they were coherent generations.

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
