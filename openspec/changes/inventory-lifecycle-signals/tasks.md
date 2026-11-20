# inventory-lifecycle-signals tasks

## 1. Signals

- [x] 1.1 Record `removedFromStore`, `archived` and `noReleaseFor12Months` in the availability sweep. Verify: `tests/unit/Service/Lifecycle/LifecycleSignalsTest.php` with a removed store app, a shipped app (no signal), an archived repository and a stale app.
- [x] 1.2 Add `ServerEolFeed` with the instance setting and a one-day cache, and the `endOfSupport` rule. Verify: a unit test with a stubbed feed, an empty address (switched off) and an unreachable feed (unknown, not end of support).
- [x] 1.3 Render the strongest signal on the card and the rest in its tooltip. Verify: a vitest spec per signal.

## 2. Server end of life

- [x] 2.1 Add `ServerEolWarningJob`, the `server_eol` subject in `Notifier`, and the date on the server section of the Advisories tab. Verify: `tests/unit/BackgroundJob/ServerEolWarningJobTest.php` fires once at 90 days and once at 30, and `tests/unit/Notification/NotifierTest.php` renders the subject.

## 3. Successors

- [x] 3.1 Add `SuccessorList` with `lib/Settings/successors.json` and the admin entries in app config, and the Settings tab list. Verify: a unit test that an entry whose successor lists no compatible version is not offered.
- [x] 3.2 Add `POST /api/app/{appId}/replace` and `ReplaceAppDialog.vue`. Verify: unit tests that a failed successor install leaves the old app enabled, and that success writes two audit rows; `tests/e2e/lifecycle.spec.ts` replaces a fixture app with a fixture successor from the forge fixture.
  - Built: `LifecycleController::replace()` (password-confirmed `POST /api/app/{appId}/replace` and `/api/v1/...`) runs `AppReplacer`, which installs the successor's newest compatible version through `installAppVersion()` with the entry's source, and only after a successful install enables it and disables the old app with a `replace` audit row; a failed install leaves the old app enabled and untouched. `src/dialogs/ReplaceAppDialog.vue` opens from the "Replace with {successor}" button on the old app's card, says the old app's data stays, and shows a failed install. Tests: `tests/unit/Service/Lifecycle/AppReplacerTest.php` (failed install leaves the old app enabled and writes no replace row, success writes the `install` and `replace` rows in that order, switch-over failure audited), `tests/unit/Controller/LifecycleControllerTest.php` (admin guard, password attribute on both paths), `src/dialogs/ReplaceAppDialog.spec.ts`, `src/App.lifecycle.spec.ts`. The e2e spec `tests/e2e/lifecycle.spec.ts` is written and waits for the Playwright job.

## 4. Close

- [ ] 4.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `inv-deprecated-apps`, `inv-end-of-support` and `ins-replace-successor` to `built` with evidence lines, then archive this change.
  - Note: the strings of this change are in `l10n/en.json` and `l10n/nl.json` (`npm run check:l10n-js` and `scripts/check-l10n-keys.cjs` pass); the matrix rows and the archive are still open.
