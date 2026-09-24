# Architecture and security boundaries

## Components

- `app/backupstatus`: Nextcloud 34 PHP app and unbundled JavaScript UI. Nextcloud's
  normal administrator/CSRF requirements protect settings and lifecycle actions.
  Ordinary authenticated users see a sanitized backup summary.
- `runtime/backupmanager`: Python 3.10+ standard-library runtime. `config.py` is the
  authoritative defaults/validation definition; `phpconfig.py` parses literal PHP
  arrays without invoking PHP; `storage.py` implements the common storage contract;
  `engine.py` coordinates consistent snapshots and verified restoration.
- `service`: fixed root entry points, systemd scheduling and asynchronous job units.
- `provider`: PHP API, server-to-server management and emergency session administration, MySQL/MariaDB state,
  and root helpers scoped to one validated `BM-000001` client directory.
- `installer`: explicit deployment or filesystem-only staging, with installation
  manifests for removal and an introspecting, non-destructive SQL migration runner.

## Privileges

The orchestrator needs root for private runtime state and switching to the service
account. Data packing, MySQL clients, configuration installation and data restoration
run as the Nextcloud service user. It invokes local Nextcloud commands as the configured Nextcloud service
user, never as root. SSH/rsync network transfers run as `backupmgr`; provider SSH
keys use separate `rrsync -wo -munge` and `rrsync -ro -munge` commands rooted at a client directory,
with the OpenSSH `restrict` option. No unrestricted shell command is granted by a
client key. Runtime code/configuration, known-host trust, job records and provider
SSH authorization are root-owned. Backup configuration and dumps contain secrets;
local staging directories are private and removed on normal completion/failure.

The web app can invoke only enumerated runtime actions via sudo. It cannot choose
runtime/Nextcloud paths or arbitrary executables. S3 credentials arrive on standard
input, are stored in a root-only file, and are never returned in settings/status or
placed in process arguments. Database credentials are supplied through private
MySQL option files. Errors deliberately omit subprocess output and HTTP bodies.

Provider PHP-FPM runs as `bmprovider`, independently of Nextcloud's `www-data` and
the `backupstore` SSH account. It may install/revoke a validated pair of public keys
or remove one validated client directory; it cannot supply a forced SSH command or
arbitrary root path. Authorized keys are replaced atomically under a lock.

## Enrollment and recovery

An unauthenticated enrollment gets only a request-status bearer token. It never
gets an approval credential. Provider administrators sign in over HTTPS using a
password hash stored outside the web root. Session cookies are secure, HTTP-only,
and SameSite Strict; actions require POST plus CSRF. Login and public enrollment
are rate limited. Email is notification only. Administrators must verify the person,
source identity and keys out of band, especially when replacing an existing client.

On approval, the status token becomes the client's API credential, stored hashed
against its permanent client ID. Recovery rotates both SSH keys and this credential
without depending on the old source ID. Rejected/expired request state remains
readable. GET handlers do not approve, reject, provision or expire database rows.

Provider-wide management uses a separate manually provisioned token. Do not place
that token on ordinary tenant installations. Rotating the administrator password
invalidates existing administrator sessions.

## Recovery-point protocol

Each point is `generations/YYYYMMDDTHHMMSSZ-<12 hex>/`. Artifacts are split into
at most 64 MiB objects named `data.000000`, `database.000000`, and `config.000000`.
`manifest.json` contains format version, creation timestamp, generation identity,
chunk sizes and SHA-256 checksums. It is uploaded last. Incomplete uploads are not
listed as recovery points and are removed after the retention interval.

Normal backups never enable or disable Nextcloud maintenance mode, including for
large, multi-chunk backups and failure cleanup. The existing capture order remains:
read effective configuration, take a single-transaction database dump, archive local
data, then serialize the captured configuration. Artifacts use the same staging,
chunking, checksums and manifest-last upload as before. A local flock prevents this
runtime's backup and restore operations from overlapping. Backups still refuse to
start if Nextcloud is already in maintenance mode, leaving that state unchanged.

The transaction gives a consistent database view for transactional tables; the live
file archive and configuration are not an atomic snapshot with that database view.
Concurrent uploads, edits, deletions or configuration changes can produce mismatches,
and checksums verify artifact integrity rather than application-level consistency.
Prefer quiet periods and avoid configuration changes during capture. Deployments
requiring a coordinated point-in-time snapshot must arrange writer coordination or
an application-consistent snapshot outside this backup mechanism. There is no
size-dependent maintenance fallback. Live restores retain their maintenance-mode
handling, including leaving maintenance enabled after a failed live restore.

The manifest provides corruption detection, not authentication against a malicious
storage administrator who can replace both data and manifest. Transport uses pinned
SSH host trust or verified HTTPS. Use independent storage access controls,
encryption at rest and immutable/offline copies as required by your threat model.

## Restoration

All artifacts are downloaded and verified before mutation. The runtime rejects
archive traversal, links and special files, checks Nextcloud version/data location,
staging capacity and the local database connection. Remote PHP is strictly parsed;
it is never evaluated. Disaster recovery writes a canonical literal configuration,
preserving the new machine's database connection, data path and trusted domains.
Current Backup Manager app configuration is preserved across database import.

A failed or interrupted live restore leaves maintenance enabled and an explicit
operator-intervention state. Cancellation is accepted only before live restore
changes. Systemd failure finalizers turn interrupted jobs into failed status.
SSH/S3 retention and deletion are scoped to the selected installation; the newest
committed point is retained. S3 versioned deletion includes historical versions
and delete markers, subject to bucket policy/Object Lock.


## Provider management boundary

The normal provider administration UI is part of Nextcloud Backupbeheer.
`ProviderAdminController` enforces administrator membership and CSRF, reads its
private server-side management token, validates HTTPS API replies, and exposes
only allowlisted request metadata. `ManagementController` authenticates that token
before calling the shared `RequestAdministration` inventory/action service.
Standalone `/admin/` calls the same service after its own secure administrator
session check; Apache Basic Auth is redundant and is not part of this model.
See [security](security.md) and the [administration report](provider-administration.md).
