# install-force-incompatible tasks

## 1. Which versions may be forced

- [ ] 1.1 Add `serverAboveMaximum()` next to `AppStoreSource::satisfiesPlatformSpec()` and stamp `serverTooNew` in `getAppVersions()` for App Store and cached forge versions (design D1). Verify: `tests/unit/Service/Source/AppStoreSourceTest.php` covers a spec whose maximum is below the server, one whose minimum is above it, both, and `*`; `tests/unit/Service/InstallerServiceCacheTest.php` covers a cached forge range.

## 2. The override

- [ ] 2.1 Add `lib/Service/Installer/ForceOverrideStore.php` (apply, record, remove only what Versioniq added) and the `forceIncompatible` argument of `installAppVersion()`, with the clean-up in its `finally` (design D2). Verify: `tests/unit/Service/Installer/ForceOverrideStoreTest.php` covers an entry already present, one added and removed on failure, and a list edited by another writer between read and write; `tests/unit/Service/InstallerServiceTest.php` covers success, failure, and a dry run that writes no config.
- [ ] 2.2 Read `forceIncompatible=1` on the install route and add `POST /api/app/{appId}/force-enable` (design D3), both password-confirmed, and the audit operation `force_incompatible`. Verify: `tests/unit/Controller/ApiTest.php` asserts 403, the audit row, and the override removed after a failed enable.

## 3. The lift

- [ ] 3.1 Add `lib/BackgroundJob/ForceOverrideReconcileJob.php` (hourly) in `appinfo/info.xml`, and the `force_lifted` subject in `Notifier` (design D4). Verify: `tests/unit/BackgroundJob/ForceOverrideReconcileJobTest.php` covers a higher major with the entry present, with the entry already cleared by Nextcloud, a version that declares the new major (kept enabled), one that does not (disabled), and an entry without a Versioniq record (untouched); `tests/unit/Notification/NotifierTest.php` renders the subject.

## 4. Page and CLI

- [ ] 4.1 Add "Install anyway" and "Needs a newer Nextcloud; cannot be forced" to the picker, `src/dialogs/ForceIncompatibleDialog.vue`, "Enable anyway" on the card, and the `forcedOn` notice (design D5). Verify: `src/components/ServerCompatBadge.spec.ts` covers the two new texts; `src/dialogs/ForceIncompatibleDialog.spec.ts` covers the warning and the password step.
- [ ] 4.2 Add `--force-incompatible` to `lib/Command/InstallVersion.php`. Verify: `tests/unit/Command/InstallVersionTest.php` covers exit 0 with the flag, 7 without, and 7 with the flag on a version that needs a newer server.
- [ ] 4.3 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 4.4 Extend `tests/e2e/forge.spec.ts`: add a release to `tests/e2e/fixtures/forge/build-artifacts.sh` (which writes `min-version="31" max-version="34"` today) that declares a maximum below the test server; assert the refusal, then "Install anyway", then the card notice, and remove the entry in teardown.

## 5. Close

- [ ] 5.1 Set the matrix row `ins-force-incompatible` to `built` with an evidence line, then archive this change.
