# Upgrading from the beta SSH/mirror implementation

Test the upgrade against a copy of the installation and provider database first.
Do not run the old dump-style schema against an existing database. The new
`provider/bin/migrate.php` introspects columns/indexes and is safe to rerun; the
individual old SQL delta files are now explanatory stubs. `schema.sql` is a
non-destructive fresh baseline including deletion requests and valid FK ordering.

## Recommended sequence

1. Disable the old timer during the deployment window and ensure no backup/restore
   is running. Back up provider metadata and current client configuration privately.
2. Stage/review both installation manifests. Deploy provider code, configure the
   dedicated FPM user and run the migration with a schema-capable database account.
   Give the runtime account only the privileges required for ordinary operations.
3. Set the provider administrator password, protect its HTTPS vhost, and retire
   legacy email approval links. Existing approval-token hashes are invalidated.
   Existing client API request tokens are backfilled onto permanent client IDs.
4. Deploy the client. The legacy shell configuration is parsed, never sourced.
   Supported connection/path/retention values and the existing timer expression
   are imported into root-only `runtime.json`. Existing JSON settings are preserved.
   Unsupported shell syntax or timer expressions stop migration for review.
5. Re-enroll existing SSH clients or recover their IDs so the provider receives
   both write and read keys. Old pending requests lack the read key and must be
   rejected/recreated. The provider's authorization file now lives in a separate
   root-owned account home; old unrestricted/legacy entries are not imported blindly.
6. Pin host trust, test the actual storage path, create a new generation, verify it,
   and perform an isolated restore before resuming the timer.

Provider helpers intentionally use the fixed storage root
`/var/lib/backupmanager-provider` and `backupstore` account. Existing installations
using other layouts need an explicit operator migration before enabling these
helpers. Source/client records and old storage are not silently relocated or deleted.

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
