# Upgrading from the beta SSH/mirror implementation

Test the upgrade against a copy of the installation and provider database first.
Do not run the old dump-style schema against an existing database. The new
`provider/bin/migrate.php` introspects tables, columns and indexes and is safe to rerun; the
individual old SQL delta files are now explanatory stubs. `schema.sql` is a
non-destructive fresh baseline including deletion requests and valid FK ordering.

## Recommended sequence

1. Disable the old timer during the deployment window and ensure no backup/restore
   is running. Back up provider metadata and current client configuration privately.
2. Stage/review both installation manifests. Deploy provider code, configure the
   dedicated FPM user, then run the read-only migration preflight below. An up-to-date
   schema needs only runtime CRUD privileges. Use separate deployment credentials
   only for the operations actually pending. Revoke the deployment grants and remove
   the temporary account/credential file after success or failure; verify with the
   unchanged runtime account after revocation before continuing.
3. Set the provider administrator password, protect its HTTPS vhost, and retire
   legacy email approval links. Existing approval-token hashes are invalidated.
   Existing client API request tokens are backfilled onto permanent client IDs.
4. Deploy the client. The legacy shell configuration is parsed, never sourced.
   Supported connection/path/retention values and the existing timer expression
   are imported into root-only `runtime.json`. Existing JSON settings are preserved.
   Unsupported shell syntax or timer expressions stop migration for review.
5. Re-enroll existing SSH clients or recover their IDs so the provider receives
   both write and read keys. Old pending requests without a distinct read key are
   expired when a fresh request is submitted. The provider's authorization file now lives in a separate
   root-owned account home; old unrestricted/legacy entries are not imported blindly.
6. Pin host trust, test the actual storage path, create a new generation, verify it,
   and perform an isolated restore before resuming the timer.

Provider helpers intentionally use the fixed storage root
`/var/lib/backupmanager-provider` and `backupstore` account. Existing installations
using other layouts need an explicit operator migration before enabling these
helpers. Source/client records and old storage are not silently relocated or deleted.

## Provider migration preflight and credentials

If the installed command prints `Migration stopped. Check schema privileges and
duplicate records; completed DDL is safe to retry.`, replace the old migration code
with the corrected provider installation before retrying. The old implementation
executes `CREATE TABLE IF NOT EXISTS` even on a current database; that statement
still requires CREATE. Do not expand runtime privileges to work around it.
The corrected no-argument command also supports the CRUD-only no-op: after a
successful empty preflight, run
`sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php` and expect
exit 0 with `Schema and data migrations are up to date; no changes required.`

`installer/install.py provider --apply` deploys files and preserves the installed
provider configuration. It does **not** migrate the database unless `--migrate` is
explicitly supplied. The installer records its file manifest before attempting an
optional migration, so a database failure does not lose the deployment inventory.
Keep backups and the maintenance window in place until verification succeeds.
Pause provider enrollment/approval activity during an actual migration too; the
migration lock serializes migrators, not application writes.

After deploying the corrected files, inspect using the ordinary runtime account:

```sh
sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php --check
```

Exit codes: **0** = nothing pending; **2** = changes pending (no writes performed);
**1** = inspection/execution failed. A schema already at this version runs with
SELECT, INSERT, UPDATE and DELETE only; it executes no CREATE/ALTER/INDEX statements.
A fully migrated database executes no data UPDATEs either. Each pending line names
the table/column/index or legacy data conversion and the privilege it needs:

- Missing table: CREATE on that table (MySQL also needs REFERENCES on FK parents).
- Missing column: ALTER on that table (MySQL may additionally require CREATE and
  INSERT for table rebuilding, as documented by that server version).
- Missing unique index: INDEX on that table; migration uses CREATE UNIQUE INDEX.
- Legacy API-token backfill: SELECT and UPDATE; obsolete email approval-token
  retirement: UPDATE. These only run when matching legacy rows exist. Permanent
  client API tokens already populated are preserved.

If only data conversions are pending, apply with the configured runtime account:

```sh
sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php
sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php --check
```

### Exact temporary grant / migrate / revoke sequence

Use this sequence **only when `--check` reports pending DDL**. No-op upgrades need
no grants; data-only migrations use the runtime account as above. The SQL below
uses the ncdev database `backupmanager_provider`, runtime account
`'backupmanager_provider'@'localhost'`, and a new, disposable deployment account
`'backupmanager_migration'@'localhost'`. For another installation, substitute the
configured database and actual matching account host. Run SQL as the database
administrator on that server; OS root alone is not database authorization.

1. Keep the timer stopped, provider enrollment/approval activity paused and backups
   available. Review the runtime-account `--check` output first. On ncdev with local
   MariaDB socket administration, open a privileged session with client history
   disabled (otherwise use your site's authenticated DBA connection):

   ```sh
   sudo env MYSQL_HISTFILE=/dev/null mariadb
   ```

   Record the existing runtime grants privately and create the temporary account
   with a freshly generated password supplied through the private DBA session:

   ```sql
   SHOW GRANTS FOR 'backupmanager_provider'@'localhost';
   CREATE USER 'backupmanager_migration'@'localhost'
     IDENTIFIED BY 'REPLACE_WITH_A_FRESH_PRIVATE_PASSWORD';
   GRANT SELECT ON backupmanager_provider.*
     TO 'backupmanager_migration'@'localhost';
   ```

   Do not use the literal placeholder password. CREATE USER deliberately fails if
   the deployment account already exists: stop and have the DBA resolve its ownership
   and grants instead of reusing an account with unknown privileges. Do not grant
   anything to the runtime account.

2. In that DBA session, execute **only the statements matching the reviewed plan**.
   These grants are restricted to the provider database, with no GRANT OPTION:

   ```sql
   -- Only if a missing table requires CREATE:
   GRANT CREATE ON backupmanager_provider.*
     TO 'backupmanager_migration'@'localhost';

   -- Only if a missing column requires ALTER:
   GRANT ALTER ON backupmanager_provider.*
     TO 'backupmanager_migration'@'localhost';

   -- Only if a missing unique index requires INDEX:
   GRANT INDEX ON backupmanager_provider.*
     TO 'backupmanager_migration'@'localhost';

   -- Only if the plan includes a legacy data conversion requiring UPDATE:
   GRANT UPDATE ON backupmanager_provider.*
     TO 'backupmanager_migration'@'localhost';
   ```

   On MySQL, additionally execute the following **only for the corresponding
   operations reported by the plan**. MariaDB's column-add operations do not need
   these MySQL-specific additional grants:

   ```sql
   -- MySQL ALTER TABLE rebuild requirements:
   GRANT CREATE, INSERT ON backupmanager_provider.*
     TO 'backupmanager_migration'@'localhost';

   -- MySQL table creation with foreign keys referencing provider parent tables:
   GRANT REFERENCES ON backupmanager_provider.*
     TO 'backupmanager_migration'@'localhost';
   ```

   Verify the temporary account before proceeding:

   ```sql
   SHOW GRANTS FOR 'backupmanager_migration'@'localhost';
   ```

   Do not grant ALL PRIVILEGES, DROP, global privileges or permanent schema access.
   GRANT/REVOKE take effect without FLUSH PRIVILEGES.

3. On the provider host, provision `/root/backupmanager-migration.json` as a regular
   root-owned file with mode 0600, using a secure editor or secret provisioning
   process. Its entire content is these two fields with the account's real password:

   ```json
   {"user": "backupmanager_migration", "password": "REPLACE_WITH_THE_SAME_PRIVATE_PASSWORD"}
   ```

   Verify its ownership and permissions before use:

   ```sh
   sudo chown root:root /root/backupmanager-migration.json
   sudo chmod 0600 /root/backupmanager-migration.json
   ```

   Passwords never go in command arguments. This file overrides only CLI username
   and password; the configured host, port, database and charset stay authoritative.
   The provider runtime configuration is not changed.

4. Inspect again using deployment credentials, review the output, then apply:

   ```sh
   sudo php /opt/backupmanager-provider/bin/migrate.php --check --credentials /root/backupmanager-migration.json
   ```

   Exit 2 is expected for reviewed pending changes; exit 1 means stop and diagnose.
   If the plan differs, resolve it before applying. If it is now empty, skip apply
   and proceed directly to credential cleanup. Otherwise:

   ```sh
   sudo php /opt/backupmanager-provider/bin/migrate.php --credentials /root/backupmanager-migration.json
   ```

   Record the result. **Perform step 5 whether apply succeeds or fails.** Do not put
   cleanup behind `&&` or a shell `set -e` path that skips it on migration failure.

5. After the migration process exits, revoke all temporary account grants and remove
   that disposable account in the DBA session. This also applies if preparation or
   inspection failed after CREATE USER succeeded:

   ```sql
   REVOKE ALL PRIVILEGES, GRANT OPTION
     FROM 'backupmanager_migration'@'localhost';
   SHOW GRANTS FOR 'backupmanager_migration'@'localhost';
   DROP USER 'backupmanager_migration'@'localhost';
   SHOW GRANTS FOR 'backupmanager_provider'@'localhost';
   ```

   Before DROP USER, the deployment account must have only USAGE; the runtime grants
   must still match their recorded SELECT, INSERT, UPDATE, DELETE grants. If cleanup
   fails, have the DBA finish it before ending the maintenance window. Once the
   account is removed, remove its private credential file from the provider host:

   ```sh
   sudo rm -- /root/backupmanager-migration.json
   ```

6. Verify using the production runtime account **after revocation**:

   ```sh
   sudo -u bmprovider php /opt/backupmanager-provider/bin/migrate.php --check
   ```

   Require exit 0 before continuing deployment acceptance. If apply failed or the
   check reports remaining changes, keep the window open and timer stopped. DDL
   already completed may have committed; reconcile the diagnostic, review a new
   plan, and repeat this sequence with fresh temporary credentials for only the
   remaining work. Do not restart with a fresh schema import or delete client data.

For an already reviewed plan, the installer equivalent of the apply command is
`provider --apply --migrate --migration-credentials /root/backupmanager-migration.json`.
The same revoke/cleanup and runtime verification steps remain mandatory. Plain
`--migrate` uses runtime credentials and reports a specific denial if DDL needs more.

Errors distinguish privilege/authentication denial, duplicate legacy data, incompatible
schema definitions and other SQL failures. SQL errors include the operation,
required privilege, SQLSTATE and numeric driver code, without raw driver text,
credentials or duplicate record values. Reconcile duplicate groups or schema
conflicts explicitly; migration does not remove client records or replace conflicting
objects. DDL can commit before a later failure. Fix the reported cause, rerun
`--check`, review what remains, then retry. Do not reimport schema.sql over live data.

Privilege references: [MariaDB GRANT](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant)
and [MySQL privileges](https://dev.mysql.com/doc/refman/8.0/en/privileges-provided.html).

## Existing backup data

Legacy `data/`, `database/` and `config/` mirrors remain untouched. They cannot be
truthfully converted automatically into a coordinated recovery point: a historical
SQL dump may not correspond to the mirrored files/configuration. The new inventory
therefore lists only newly committed generations. Preserve legacy copies until new
recovery points have passed acceptance testing.

To recover a legacy mirror, use a separate isolated Nextcloud installation of the
matching version. Have an operator verify the dump, review literal configuration,
restore using the appropriate restricted read key, and reconcile file/database
consistency. Once the recovered installation is verified, make a new coherent
recovery point. Unsafe legacy root scripts that evaluated PHP have been replaced;
old filenames are retained as guarded entry points accepting new generation IDs.

Remote retention applies to new generations. Old local dump directories and legacy
remote mirrors require an explicit operator decision and are not deleted during
upgrade or client uninstall. File manifests track all new installation files;
review/remove obsolete pre-manifest helper files and older sudo rules during the
operator deployment window. Never leave the previous provider's broad sudo grants
or public unauthenticated administration route enabled alongside the new release.


## Storage configuration update for existing generation-based installations

These are future operator deployment steps, not actions performed by the working-tree
change. This update does not require a provider database migration, new client ID,
re-enrollment, or moving/recreating any provider storage. Preserve
`/var/lib/backupmanager-provider/<client-id>` and its existing contents.

1. Schedule an update window with no backup, restore or enrollment/key recovery in
   progress. Privately back up runtime.json, its referenced credential files, SSH
   keys/known_hosts and provider configuration. Keep their restrictive permissions.
2. Stage and review the updated client and provider installers. Deploy the app and
   Python runtime/helpers together: recovery now validates the connection and
   activates replacement keys in the same helper call. Do not operate recovery with
   a mixture of old app code and new helpers (or vice versa).
3. Preserve the installed provider configuration. Confirm `storage.host` is reachable
   from clients and `storage.port` is an integer SSH port; the example now intentionally
   has no localhost fallback. The account and root remain `backupstore` and
   `/var/lib/backupmanager-provider`. Existing allocations are unchanged.
4. The client installer reads Nextcloud's effective literal config, including external
   datadirectory overrides. Existing runtime.json is preserved and checked against
   that source. A mismatch requires an operator to reconcile the stored path with
   the intended active Nextcloud directory; it never selects a default silently.
5. Existing JSON without `storage_profiles` remains readable. The next settings save
   or provider configuration application records mode-specific settings in that
   root-only section automatically. No separate migration command is needed. A
   rollback to older code requires the matching pre-update configuration, since old
   code rejects unknown configuration keys; retain its referenced credentials too.
6. Refresh the admin page and verify each destination/mode with the installed assets.
   Confirm managed values, host pinning and the provider client ID. For AWS, confirm
   the region matches the bucket; AWS mode now derives its regional endpoint. Sites
   intentionally using a custom endpoint must use S3-compatible mode. Then perform
   an explicitly authorized connection test and isolated recovery verification before
   resuming the timer. Connection tests write and remove a unique probe generation.

No live Nextcloud browser acceptance test or real AWS/S3-compatible account test is
performed by the isolated repository suite; include those in deployment acceptance.

For the BM-000007/ncdev upgrade, use the reviewed sequence and validation notes in
[ncdev-upgrade.md](ncdev-upgrade.md).


## Legacy access-recovery follow-up

Deploy matching provider, runtime and app files for app version 0.2.4. Recovery
approval now returns the approved public key pair, which the client checks against
its staged manifest before activation. Keep the existing permanent client ID and
allocation; do not enroll a replacement to bypass an expired legacy request.
Recovery now requires the consent checkbox and displays the created request ID or
a persistent localized failure next to the button, with safe technical details.
Old staged private keys are normalized to 0600 before deriving their public keys.

Provider timestamps are UTC regardless of the PHP timezone. Obsolete/expired
write-only recovery requests are retired only for the client being recovered.
A genuinely usable pending request still requires review rather than silent
replacement. A superseded approval cannot reactivate an old token/key response.
See [the continuous fixture and its boundaries](ncdev-upgrade.md#continuous-isolated-acceptance-fixture)
for exactly what is exercised offline and what remains production acceptance.


## CLI success does not validate the HTTP provider

The installer deploys to `/opt/backupmanager-provider`; an existing web server may
still serve a legacy `/var/www/backupmanager-provider` tree. The HTTP worker must
also use the dedicated bmprovider pool, not the Nextcloud www-data pool. A successful
CLI migration under bmprovider does not establish that HTTPS reads the same code
or configuration. HTTP database authentication failures cannot be repaired by
adding schema privileges to the runtime account.

Run the read-only `python3 installer/install.py provider --check-web`. It requires
the enabled provider document root `/opt/backupmanager-provider/public`, PHP socket
`/run/php/backupmanager-provider.sock` and an FPM pool with user/group bmprovider.
The Apache and nginx examples show the bindings; the dedicated pool is in
`provider/config/php-fpm.conf.example`. Preserve existing HTTPS certificates and
site-specific access controls when adapting them. Validate web/FPM syntax before
reloading. The checker inspects files, not the server's loaded configuration.

Provider `--apply` now returns **2** after file installation when these web checks
fail. This does not undo the installed files or automatically edit/reload web
configuration. Finish the HTTPS/FPM wiring, require `--check-web` exit 0, then run
migration preflight and HTTP acceptance. Explicit `--migrate` retains its own
migration behavior; do not request it just to fix a web deployment mismatch.


FPM paths and service names are installation-specific. Use
`python3 installer/install.py provider --detect-fpm` and the
[portable FPM selection procedure](operations.md#selecting-the-provider-php-fpm-service)
before changing a pool. CLI PHP is not evidence of the active FPM version.
Existing provider pool ownership takes precedence over other installed versions;
ambiguous fresh installations require `--php-fpm-version VERSION`. Missing or
unusable FPM fails before provider `--apply` writes files. Keep a working existing
pool unchanged; the supplied pool/socket configuration is version-independent.
The checker now also rejects stale FPM installations, unreferenced pools and
multiple listeners claiming the provider socket.

Apache must load `proxy_fcgi` to execute the dedicated FPM handler. Merely having
an active pool/socket and passing Apache syntax checks is insufficient: without
the module the old example can serve PHP source as HTTP 200. Enable `proxy` and
`proxy_fcgi`, validate Apache, then reload it. Merge the fail-closed module guard
from the supplied example while preserving existing TLS/admin controls. No FPM
version/path change is necessary. The updated `--check-web` rejects a missing
module. Verify that an unknown `/api/v1/` route returns JSON HTTP 404; file checks
do not prove loaded server behavior.

The client now rejects non-JSON media types and malformed API envelopes, persists
localized recovery errors across reloads, and stops obsolete polling after failed
replacement submissions. Its PHP error-log diagnostics contain response metadata,
not response bodies, source code or tokens. Deploy the updated Nextcloud app and
refresh cached app assets through the normal upgrade procedure.


## Provider administration 0.2.4

Normal provider request administration now lives in Nextcloud Backupbeheer, with
explicit administrator/CSRF guards and server-side management-token calls.
Deploy provider and client together. Existing pending recovery credentials remain
valid; do not generate a replacement request during upgrade. No new schema changes
are needed. Preserve `/etc/backupmanager-provider/config.php`; all entry points
now use that canonical external file directly. Port strings containing a valid
number are normalized; missing/invalid SSH endpoints block approval explicitly.

Standalone `/admin/` remains password-session protected for emergencies. Remove
its redundant Apache Basic Auth Location block, retain HTTPS and the fail-closed
FPM handler, validate and reload Apache. The deployment checker reports remaining
Basic Auth as an incomplete single-login setup. This does not disable the PHP
administrator authentication or alter a correctly installed FPM pool.

Use the [ordered, review-only ncdev procedure](provider-administration.md#review-only-ncdev-operator-procedure).
The provider and client versions are 0.2.4. Do not automatically approve requests,
change allocations, rotate management tokens or enable the timer during upgrade.
