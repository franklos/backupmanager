# Backup Manager for Nextcloud

Backup Manager 0.2 provides a Nextcloud 34 administration app, an optional managed
SSH provider, and a systemd backup/recovery runtime. Supported storage backends are
restricted SSH/rsync, AWS S3, and HTTPS S3-compatible services.

Backups are complete, checksummed recovery points containing the MySQL/MariaDB
database, effective literal Nextcloud configuration, and local data. A manifest
commits a recovery point only after every artifact has uploaded. Restores run as
background jobs and leave maintenance enabled if live restoration fails.

## Documentation

- [Installation, provider setup, enrollment and operation](docs/operations.md)
- [Upgrade and compatibility](docs/upgrading.md)
- [S3 configuration and permissions](docs/s3.md)
- [Architecture and security boundaries](docs/architecture.md)
- [Testing and deployment acceptance](docs/testing.md)

## Development checks

Run `bash tests/run.sh`. Tests use temporary directories, synthetic credentials,
mocked privileged helpers, and an isolated MariaDB instance when its binaries are
available. They do not use project-local configuration or a running installation.

Stage an installation without changing the host:

```sh
python3 installer/install.py client --destdir /tmp/backupmanager-client-stage
python3 installer/install.py provider --destdir /tmp/backupmanager-provider-stage
```

Deployment is an explicit operator action using `--apply`; staging never creates
users, generates credentials, changes services, or migrates databases. No package
installation is performed by the installer.

## Scope

Nextcloud application binaries and external/object primary storage are not backed
up. Reinstall the same Nextcloud version and required apps before recovery.
MySQL/MariaDB, local regular-file data, and literal PHP configuration arrays are
supported. Dynamic PHP configuration, symlink/special-file data and other database
engines fail explicitly rather than producing an incomplete recovery point.

This release requires staging acceptance on the actual Nextcloud, OpenSSH and S3
implementations before production use; automated fixtures cannot certify a live
installation. See the acceptance procedure in the testing guide.

## License

GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later).
Copyright (c) 2026 Frank Los.
