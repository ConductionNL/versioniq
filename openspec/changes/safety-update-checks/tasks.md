# safety-update-checks tasks

## 1. Dry run validation

- [ ] 1.1 Move the `info.xml` validation of `SelectedReleaseInstallerService` into one method and call it on the unpacked root in a dry run (design D1). Verify: `tests/unit/Service/InstallerDryRunSideEffectsTest.php` gains a dry run that fails on compatibility and on a dependency, with no config, file or maintenance change.

## 2. Checks

- [ ] 2.1 Add `lib/Service/Checks/UpdateCheck.php`, `lib/Service/Checks/CheckStore.php` (verdict checks, encrypted token, at most 10) and read command checks from `versioniq.update_checks` (design D2). Verify: `tests/unit/Service/Checks/CheckStoreTest.php` covers the cap, an http address refused, no token in `read()`, and a malformed system value ignored with a log line.
- [ ] 2.2 Add `lib/Service/Checks/HttpVerdictCheck.php`, `lib/Service/Checks/CommandCheck.php` and `lib/Service/Checks/CheckRunner.php` (design D3). Verify: `tests/unit/Service/Checks/CheckRunnerTest.php` covers a 2xx and a 404 verdict, a timeout, URL encoding of placeholders, a command with exit 0 and 1, arguments passed without a shell (an argument containing `;` reaches the program as text), the web-context skip above 60 s, and the 2 KiB output cap.
- [ ] 2.3 Call `CheckRunner::run()` in the dry-run branch of both installers and pass `checks` through `InstallerService`. Verify: `tests/unit/Service/InstallerServiceTest.php` asserts `checks` in a dry-run payload for each installer kind.
- [ ] 2.4 Add `PUT /api/update-checks` to `SettingsController` and include verdict and command checks in `GET /api/instance-settings`. Verify: `tests/unit/Controller/SettingsControllerTest.php` asserts 403, the audit row, and command checks read-only.

## 3. Gate

- [ ] 3.1 Add category `checks_failed` to `FailureClassifier`, the dry run and checks before the real install in `AutoUpdateJob::attemptInstall()` when a check matches, and exit code 12 in `lib/Command/InstallVersion.php` (design D4). Verify: `tests/unit/BackgroundJob/AutoUpdateJobTest.php` covers no matching check (one real install), a failed check (no install, ledger failure, notification with the check name), and passing checks (dry run then install); `tests/unit/Command/InstallVersionTest.php` covers exit 12.

## 4. Page

- [ ] 4.1 Add `src/components/UpdateChecksPanel.vue` to the Settings tab and the check results to `src/components/InstallResultNotices.vue`. Verify: `src/components/UpdateChecksPanel.spec.ts` covers adding, editing and the read-only command list; `src/components/InstallResultNotices.spec.ts` covers passed, failed and skipped checks.
- [ ] 4.2 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 4.3 Extend `tests/e2e/version-management.spec.ts`: add a verdict route to `tests/e2e/fixtures/forge/server.mjs` that answers 404 for one version and 200 for another, store a verdict check pointing at it with `occ config:app:set versioniq update_checks` (the form accepts https only; `tests/e2e/fixtures/forge/bootstrap.sh` sets the http forge addresses the same way), and assert both dry-run results in the picker.

## 5. Close

- [ ] 5.1 Set the matrix rows `saf-test-before-apply` and `aut-automerge` to `built` with evidence lines, then archive this change.
