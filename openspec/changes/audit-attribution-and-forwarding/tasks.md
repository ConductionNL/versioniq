# audit-attribution-and-forwarding tasks

## 1. Origin

- [ ] 1.1 Add the `origin` column migration and `AuditEntry::origin`. Verify: `tests/unit/Db/AuditEntryTest.php` and a migration run in the PHPUnit database bootstrap.
- [ ] 1.2 Pass the origin from `ApiController` (web), `InstallVersion` (cli), `AutoUpdateJob` (job) and `PinDriftHandler` (listener) through `installAppVersion()` and both installers into `AuditLogger::record()`. Verify: unit tests per caller assert the origin on the recorded row; `tests/e2e/jobs.spec.ts` runs the job on the `fixtureapp` fixture and reads `origin: job` from `GET /api/audit`.
- [ ] 1.3 Show the origin in `HistoryPanel.vue` and add the filter. Verify: a vitest spec with one row per origin and an old row with none.

## 2. Rule changes

- [ ] 2.1 Audit `setPolicy`, `clearPolicy`, `updateAutoUpdateSettings` and `updateAdvisorySettings` with before and after. Verify: `tests/unit/Controller/ApiTest.php` asserts one row per write with the expected message.
- [ ] 2.2 Show "Set by {user} on {date}" on the policy selector. Verify: `PolicySelector.spec.ts`.

## 3. Forwarding

- [ ] 3.1 Dispatch `CriticalActionPerformedEvent` after each insert, with the sanitised message. Verify: `tests/unit/Service/Audit/AuditLoggerTest.php` asserts the event and that a token in a message is redacted in it.
- [ ] 3.2 Add the activity provider and setting, register them in `info.xml`, and publish one event per admin. Verify: unit tests of the provider's rendering and of a failing activity manager (install result unchanged); on a test instance the admin's activity stream shows the install.

## 4. Close

- [ ] 4.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `aud-auto-attributed`, `aud-log-policy`, `aud-siem` and `aud-activity-stream` to `built` with evidence lines, then archive this change.
