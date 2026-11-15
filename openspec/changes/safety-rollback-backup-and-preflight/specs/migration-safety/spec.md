# migration-safety Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [safety-rollback-backup-and-preflight](../../)

## ADDED Requirements

### Requirement: The app card offers the version before the last update as a rollback target

The last-known-good record MUST keep the version that ran before the last successful install as `previous`. The app card MUST offer "Roll back to <previous>". When the last-known-good version differs from both the installed version and `previous`, the card MUST also offer "Roll back to the last good version (<version>)".

#### Scenario: An admin undoes an update made through Versioniq

- **GIVEN** Dossiq ran 0.4.44 and an admin updated it to 0.4.48 through Versioniq
- **WHEN** the admin opens the Apps tab
- **THEN** the Dossiq card MUST offer "Roll back to 0.4.44"

#### Scenario: Two rollback targets

- **GIVEN** the last good version of Dossiq is 0.4.46, `previous` is 0.4.44 and 0.4.48 was put in place outside Versioniq
- **WHEN** the admin opens the Apps tab
- **THEN** the card MUST offer "Roll back to 0.4.44" and "Roll back to the last good version (0.4.46)"

### Requirement: A real install keeps a restorable backup of the app folder and its tables

Before a real install replaces an app, Versioniq MUST keep a backup of the old app folder and of the database tables the app's own migrations created. The backup MUST be kept for the number of days set in `backup.keep_days` (default 14) and then removed. The install result MUST say that a backup was made, its size and until when it is kept. With `backup.keep_days` set to 0 no backup is made and the result MUST say so.

#### Scenario: The install result names the backup

- **GIVEN** `backup.keep_days` is 14
- **WHEN** an admin updates Dossiq on 1 October
- **THEN** the result MUST show "Backup made: database and app folder" with its size and "kept until 15 Oct"

#### Scenario: Expired backups are removed

- **GIVEN** a backup older than `backup.keep_days`
- **WHEN** the daily prune runs
- **THEN** that backup MUST be removed

### Requirement: Restoring a broken install puts back files and tables

When an install ends installed-but-broken, the result MUST offer "Restore installation". Restoring MUST put back the old app folder and the app's tables from the backup made before that install, then the previous installed version and enabled state. It MUST require password confirmation. Without a backup it MUST refuse and point to a rollback instead.

#### Scenario: A failed migration is undone

- **GIVEN** an update of Dossiq to 0.4.50 applied one migration and then failed to finalize
- **WHEN** the admin chooses "Restore installation" and confirms their password
- **THEN** Dossiq MUST run 0.4.48 again with the tables as they were before the update
- **AND** the audit trail MUST record the restore
