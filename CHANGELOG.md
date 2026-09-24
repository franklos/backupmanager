# Changelog

## 0.2.4

### Added

- Managed provider administration/recovery in Nextcloud: enrollment and recovery inventories, explicit approval/rejection, request history, and Dutch/English interfaces. Standalone provider administration shares the same workflow.
- Refresh provider assignment for an existing managed client without creating another recovery request or rotating approved keys.
- Provider configuration and Apache/PHP-FPM deployment checks, plus isolated recovery, management, browser, and online-backup regression coverage.

### Fixed

- BM-000007 legacy managed-assignment recovery fix: replace stale localhost connection details with the canonical provider endpoint while preserving the permanent client ID, allocation, approved keys, and backup generations.
- Canonical provider endpoint handling now uses configured storage host/port and the restricted account instead of stale allocation fields; managed runtime profiles and app settings stay synchronized.
- Recovery validates the approved key pair before retryable activation, rejects expired/superseded requests, and rolls back provider authorization changes on approval failure.
- Late status polls no longer overwrite an edited storage form or revert managed selection. SSH host verification and connection-test results are invalidated when the endpoint or credentials change, with clearer failure reporting.

### Changed

- Normal backups no longer use Nextcloud maintenance mode, including failure cleanup and large backups. They refuse an already-enabled maintenance state; live restores retain maintenance handling.
- Backup status/UI improvements include localized errors, readable timestamps, expandable technical details, responsive layouts, and clearer recovery and connection-test progress/cancellation states.
- Manual SSH, managed SSH, AWS S3, and S3-compatible settings retain separate profiles. AWS endpoints derive from the selected region; S3-compatible endpoints and credential files receive stricter validation.
- Legacy upgrades preserve existing keys, honor effective Nextcloud data-directory settings, and provide clearer migration/deployment diagnostics.

### Known issues / remaining work

- AWS S3 and S3-compatible support exists but still needs end-to-end testing against real services. The S3-compatible VM test is still pending.
- Live Nextcloud/provider deployment acceptance remains required; isolated fixtures do not certify a production installation.
- Online backups are not an atomic database/files/configuration snapshot. Concurrent writes can cause cross-component inconsistencies; coordinated snapshots require separate writer coordination.
- Release metadata and documentation identify 0.2.4, but enrollment still sends hard-coded `client_version: 0.2.0` in `app/backupstatus/lib/Controller/SettingsController.php`. This remains unchanged because this release-preparation pass does not modify functional code.
