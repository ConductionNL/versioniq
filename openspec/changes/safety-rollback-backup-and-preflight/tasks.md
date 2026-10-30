# safety-rollback-backup-and-preflight tasks

## 1. Rollback target

- [ ] 1.1 Add `previous` to `lib/Service/Lkg/Lkg.php` and its array form, and read and write it in `LkgStore`. Verify: `tests/unit/Service/Lkg/LkgStoreTest.php` reads an old record without `previous`.
- [ ] 1.2 In `InstallFinalizer`, move the current record into `previous` before writing the new one, unless the version is unchanged. Verify: `tests/unit/Service/Installer/InstallFinalizerTest.php`.
- [ ] 1.3 Show "Roll back to <previous>" and, when different, "Roll back to the last good version" on the card in `src/App.vue`. Verify: `src/App.appCard.spec.ts` covers one and two targets.

## 2. Disk space

- [ ] 2.1 Add `EnvironmentCheck::hasSpaceFor()` and category `preflight_space` in `FailureClassifier` (HTTP 409). Call it from both installers before download. Verify: `tests/unit/Service/Installer/EnvironmentCheckTest.php` with a free-space double; `InstallerServiceTest` asserts no download after a refusal.
- [ ] 2.2 Show the free space on the progress step in `src/components/InstallResultNotices.vue`. Verify: `InstallResultNotices.spec.ts`.

## 3. Backup and restore

- [ ] 3.1 Add `lib/Service/Installer/AppTableDumper.php` (tables from the app's migration schema, rows as JSON) and `lib/Service/Installer/AppBackupStore.php` (IAppData, zip plus `tables.json`, `backup.keep_days`). Verify: `tests/unit/Service/Installer/AppTableDumperTest.php` and `AppBackupStoreTest.php`.
- [ ] 3.2 Write a backup in both installers before replacing the folder; report size and keep-until in the result. Verify: `tests/unit/Service/InstallerRecoveryTest.php`.
- [ ] 3.3 Prune expired backups in `lib/BackgroundJob/PruneAuditJob.php`. Verify: `tests/unit/BackgroundJob/PruneAuditJobTest.php`.
- [ ] 3.4 Add `POST /api/apps/{appId}/restore` in `ApiController` (admin, `PasswordConfirmationRequired`, audited) and "Restore installation" on the result card. Verify: `tests/unit/Controller/ApiTest.php` covers a restore and a refusal without backup.
- [ ] 3.5 Add the strings to `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js`. Verify: `npm run check:l10n-js`.

## 4. Close

- [ ] 4.1 Set the matrix rows `ins-rollback`, `saf-preflight`, `saf-backup-before` and `saf-finalize-recovery` to `built` with evidence lines, then archive this change.
