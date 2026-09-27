# inventory-lifecycle-signals tasks

## 1. Signals

- [ ] 1.1 Record `removedFromStore`, `archived` and `noReleaseFor12Months` in the availability sweep. Verify: `tests/unit/Service/Lifecycle/LifecycleSignalsTest.php` with a removed store app, a shipped app (no signal), an archived repository and a stale app.
- [ ] 1.2 Add `ServerEolFeed` with the instance setting and a one-day cache, and the `endOfSupport` rule. Verify: a unit test with a stubbed feed, an empty address (switched off) and an unreachable feed (unknown, not end of support).
- [ ] 1.3 Render the strongest signal on the card and the rest in its tooltip. Verify: a vitest spec per signal.

## 2. Server end of life

- [ ] 2.1 Add `ServerEolWarningJob`, the `server_eol` subject in `Notifier`, and the date on the server section of the Advisories tab. Verify: `tests/unit/BackgroundJob/ServerEolWarningJobTest.php` fires once at 90 days and once at 30, and `tests/unit/Notification/NotifierTest.php` renders the subject.

## 3. Successors

- [ ] 3.1 Add `SuccessorList` with `lib/Settings/successors.json` and the admin entries in app config, and the Settings tab list. Verify: a unit test that an entry whose successor lists no compatible version is not offered.
- [ ] 3.2 Add `POST /api/app/{appId}/replace` and `ReplaceAppDialog.vue`. Verify: unit tests that a failed successor install leaves the old app enabled, and that success writes two audit rows; `tests/e2e/lifecycle.spec.ts` replaces a fixture app with a fixture successor from the forge fixture.

## 4. Close

- [ ] 4.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `inv-deprecated-apps`, `inv-end-of-support` and `ins-replace-successor` to `built` with evidence lines, then archive this change.
