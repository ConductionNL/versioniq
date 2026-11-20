# Design: safety-rollback-backup-and-preflight

Read against `development` at a2ddbec (2026-10-23).

## Context

- `InstallFinalizer` writes the last-known-good record only on success (`lib/Service/Installer/InstallFinalizer.php:165-173`), replacing the previous one.
- `SelectedReleaseInstallerService::backupExistingFolder()` renames the app folder aside (line 714) and `restoreFromBackup()` renames it back (line 742); the backup is deleted after a successful finalize.
- `EnvironmentCheck::inspect()` checks writability and dev-checkout signals only (lines 60-78).
- The audit pruner `PruneAuditJob` already runs daily.

## Decisions

### D1. `Lkg` gains `previous`

`Lkg` gets an optional `previous: {version, finalizedAt, sourceId}`. On success the finalizer moves the current record into `previous` before writing the new one, unless the version did not change. The card shows "Roll back to <previous>" and, when it differs, "Roll back to the last good version (<lkg>)".

### D2. Space check before download

`EnvironmentCheck::hasSpaceFor(appPath, packageBytes)` requires free space of at least three times the package size in the apps folder (old copy, new copy, backup) and two times in the temp folder. An unknown package size falls back to 50 MB. A refusal is classified `preflight_space` with HTTP 409 and a hint naming the free and needed space.

### D3. Backup store in app data

`AppBackupStore` writes to `IAppData` folder `backups/<appId>/<timestamp>/`: the old app folder as a zip and `tables.json` from `AppTableDumper`. The tables are those the app's own migrations created, read from the schema the app's migration classes build (`MigrationService` with a recording `ISchemaWrapper`), never by name prefix. Kept `backup.keep_days` days (app config, default 14, 0 turns backups off and says so on the result card).

### D4. Restore puts back files and tables

`POST /api/apps/{appId}/restore` (admin, password confirmation) restores the newest backup: folder first, then the tables in one transaction, then the previous `installed_version` and enabled state. The result says what was restored. A restore without a backup is refused with a hint to roll back instead.
