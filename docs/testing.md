# Tests and release acceptance

`bash tests/run.sh` runs standard-library Python unittests, PHP authorization tests,
PHP lint, JavaScript syntax checks, Python AST checks and `git diff --check`.
No packages are installed. Test fixtures use synthetic credentials and public keys.
The runner excludes the real ignored provider configuration from lint/execution.

The suite covers literal configuration parsing and execution rejection; archive
traversal/link rejection; recovery-point commit/integrity; fail-closed restoration;
retention; locking; S3 signing headers/pagination/prefix confinement; restricted key
pair provisioning; and staged client/provider install, repeat upgrade and removal.

When MariaDB tools are installed, a real integration test initializes a fresh
`/tmp/bm-integration-...` datadir, starts a private Unix-socket server with networking
disabled, and terminates it at test completion. The test refuses any other socket
path. It tests migration twice over legacy-shaped records, permanent-token
backfill, approval, reenrollment, recovery and deletion failure/retry. Privileged
helpers are replaced by a test-only executable that never delegates to sudo.
The same private server exercises real SQL dump/import and file restoration.
A separate real rsync/rrsync test uses a local transport bridge to verify read/write
key restrictions, traversal rejection, inventory and deletion without an SSH server.
No production configuration or existing database is read. If binaries are missing,
the test is explicitly skipped rather than replaced with a live database.

## Required staging acceptance before deployment

Automated fixtures do not replace a real Nextcloud 34 restore exercise or certify
your SSH/S3 provider's behavior. On an isolated machine with synthetic data:

1. Stage and deploy client/provider files; verify sudo syntax, root ownership,
   dedicated FPM isolation, HTTPS login and unauthenticated API rejection.
2. Enroll, approve, pin a host key, test storage, pause/resume and recover a client.
   Confirm shell commands and cross-client paths fail for both SSH keys, the write
   key cannot read, and the read key cannot write/delete.
3. Populate Nextcloud with files and metadata. Back up; change both; restore each
   supported mode. Verify file hashes, database state, maintenance transitions,
   preserved current Backup Manager credentials and data-fingerprint refresh.
4. Interrupt a restore after maintenance begins. Confirm the job fails visibly,
   maintenance stays enabled, and no further automatic restore proceeds unchecked.
5. Repeat with AWS S3 and your compatible endpoint, including temporary credentials,
   restricted IAM, versioned retention, Object Lock rejection and simulated network
   failure. Verify unrelated prefixes and legacy mirrors remain unchanged.
6. Upgrade a copy of the previous provider database and configuration. Verify row
   preservation, duplicate detection and a second no-op migration. Test both local
   removal modes and remote deletion retry.

Do not execute these acceptance steps against the running host used for development.
