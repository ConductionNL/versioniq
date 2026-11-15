# safety-rollback-backup-and-preflight tasks

## 1. Rollback target

- [x] 1.1 Add `previous` to `lib/Service/Lkg/Lkg.php` and its array form, and read and write it in `LkgStore`. Verify: `tests/unit/Service/Lkg/LkgStoreTest.php` reads an old record without `previous`. Built 14 Nov: `Lkg::$previous` (one deep), omitted from the stored JSON when absent; `LkgStoreTest::testAnOldRecordReadsWithoutPreviousAndANewOneWithIt`, `LkgTest`.
- [x] 1.2 In `InstallFinalizer`, move the current record into `previous` before writing the new one, unless the version is unchanged. Verify: `tests/unit/Service/Installer/InstallFinalizerTest.php`. Built 14 Nov: `Lkg::after()`; covered in `InstallFinalizerGoldenTest::testASuccessfulUpdateKeepsTheReplacedRecordAsPrevious` (there is no `InstallFinalizerTest.php`).
- [x] 1.3 Show "Roll back to <previous>" and, when different, "Roll back to the last good version" on the card in `src/App.vue`. Verify: `src/App.appCard.spec.ts` covers one and two targets. Built 14 Nov: `lkgRollbackTargets()` in `src/utils/migrationSafety.ts`; a record without `previous` keeps its single "Roll back to <version>" button; strings in `l10n/en|nl`.

## 2. Disk space

- [x] 2.1 Add `EnvironmentCheck::hasSpaceFor()` and category `preflight_space` in `FailureClassifier` (HTTP 409). Call it from both installers before download. Verify: `tests/unit/Service/Installer/EnvironmentCheckTest.php` with a free-space double; `InstallerServiceTest` asserts no download after a refusal. Built 14 Nov: checked once in `InstallerService` before release resolution, which both installers go through, with the 50 MB default size since the release is not resolved yet; unknown free space does not block.
- [x] 2.2 Show the free space on the progress step in `src/components/InstallResultNotices.vue`. Verify: `InstallResultNotices.spec.ts`. Built 14 Nov: the outcome carries the pre-flight `space` (`InstallRun::$space`, `InstallerServiceTest`); "Environment checked: <free> in the apps folder, <free> in the temp folder; the app folder is writable." in `InstallResultNotices.vue`, kept by `normalizeInstallResult()` only when measured.

## 3. Backup and restore

- [x] 3.1 Add `lib/Service/Installer/AppTableDumper.php` (tables from the app's migration schema, rows as JSON) and `lib/Service/Installer/AppBackupStore.php` (IAppData, zip plus `tables.json`, `backup.keep_days`). Verify: `tests/unit/Service/Installer/AppTableDumperTest.php` and `AppBackupStoreTest.php`. Built 15 Nov: the dumper runs the app's `lib/Migration/Version*` steps against a schema that starts empty and keeps the tables that exist now; one app-data folder per backup (`backup-<createdAt>-<appId>`, since only the app-data root lists folders) with `app.zip`, `tables.json`, `meta.json`.
- [x] 3.2 Write a backup in both installers before replacing the folder; report size and keep-until in the result. Verify: `tests/unit/Service/InstallerRecoveryTest.php`. Built 15 Nov: `InstallBackup::make()` runs while the old folder is still in place (its migration classes load from there); a failed backup is reported on the result and does not stop the install; the result card says "Backup made: database and app folder, <size>, kept until <date>." or that backups are off.
- [x] 3.3 Prune expired backups in `lib/BackgroundJob/PruneAuditJob.php`. Verify: `tests/unit/BackgroundJob/PruneAuditJobTest.php`. Built 15 Nov: pruned first, a failure is logged and the audit prune still runs.
- [x] 3.4 Add `POST /api/apps/{appId}/restore` in `ApiController` (admin, `PasswordConfirmationRequired`, audited) and "Restore installation" on the result card. Verify: `tests/unit/Controller/ApiTest.php` covers a restore and a refusal without backup. Built 15 Nov: `AppRestorer` puts back the folder, then the tables in one transaction, then `installed_version` and `enabled`, audited as `restore`; 409 `no_backup` without a backup, pointing to a rollback; the button shows on an installed-but-broken result (`src/App.restoreInstall.spec.ts`); the route is in `openapi.json`.
- [x] 3.5 Add the strings to `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js`. Verify: `npm run check:l10n-js`. Built 15 Nov: seven strings, added after the pre-flight line mid-catalogue; the `.js` regenerated.

## 4. Close

- [ ] 4.1 Set the matrix rows `ins-rollback`, `saf-preflight`, `saf-backup-before` and `saf-finalize-recovery` to `built` with evidence lines, then archive this change.
