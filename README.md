# Backup Manager for Nextcloud

Backup Manager is an open-source backup and recovery solution for Nextcloud.

The goal of Backup Manager is to manage the complete backup and recovery process from within Nextcloud, rather than only displaying whether a backup succeeded.

Backup Manager provides the foundation for:

- automated backup of Nextcloud data;
- automated database backups;
- monitoring of backup status and backup storage;
- verification of successful backups;
- recovery and restore management;
- controlled and safe removal of backups;
- provider-neutral backup storage.

The Nextcloud app presents backup and recovery information to administrators, while privileged backup operations are handled by separate system services. This keeps system credentials and storage-provider-specific logic outside the Nextcloud application.

Backup Manager is designed to work with different backup storage providers. The Nextcloud app itself does not depend on a specific hosting or storage provider.

## Status

Backup Manager is currently in beta development.

The current version has been developed and tested with Nextcloud 34.

## License

Backup Manager is free and open-source software licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later).

Copyright (c) 2026 Frank Los
