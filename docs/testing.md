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

## Online-backup regressions

Run the focused runtime and failure tests from the repository root:

```sh
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=tests:runtime python3 -m unittest test_runtime test_failures -v
```

These tests use temporary directories and in-memory storage. They cover successful
backup, dump/archive/upload failures, interruption cleanup, preservation of an
already-enabled maintenance state, and unchanged restore maintenance handling.
The large-backup regression archives more than 64 MiB of incompressible fixture
data to exercise the actual multi-chunk path and verifies artifact integrity while
asserting that no maintenance command is issued. It needs a few hundred MiB of
available memory and temporary disk space. Tests do not establish atomic consistency
between an actively changing database and filesystem.

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


Provider administration regressions include Nextcloud administrator/CSRF rejection,
server-side token non-disclosure, malformed/HTML-200 API replies, UTC expiry,
canonical configuration preservation and port validation, real standalone session
login/rotation, and exact rollback of legacy authorization lines. The continuous
legacy fixture now approves through Nextcloud's management controller and HTTPS
provider API; with `BM_BROWSER_TESTS=1` it uses actual admin templates/JS for
management, rejection and approval in Dutch/English and desktop/narrow layouts.
It then activates the same recovery request, tests both SSH keys, creates/discovers
a generation and performs an isolated restore. No live request is used.
