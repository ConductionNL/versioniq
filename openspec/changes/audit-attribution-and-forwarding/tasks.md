# audit-attribution-and-forwarding tasks

## 1. Origin

- [x] 1.1 Add the `origin` column migration and `AuditEntry::origin`. Verify: `tests/unit/Db/AuditEntryTest.php` and a migration run in the PHPUnit database bootstrap.
  - `app_versions_audit.origin` (nullable, `Version1005Date20261114120000`) and `AuditEntry::origin`, serialised as `origin` (null on older rows). Tested in `tests/unit/Controller/ApiTest.php` and `tests/unit/Service/Audit/AuditOriginAndForwardingTest.php`.
- [x] 1.2 Pass the origin from `ApiController` (web), `InstallVersion` (cli), `AutoUpdateJob` (job) and `PinDriftHandler` (listener) through `installAppVersion()` and both installers into `AuditLogger::record()`. Verify: unit tests per caller assert the origin on the recorded row; `tests/e2e/jobs.spec.ts` runs the job on the `fixtureapp` fixture and reads `origin: job` from `GET /api/audit`.
  - Design correction: instead of a parameter through `installAppVersion()` and both installers, `AuditOrigin` carries the origin of what is running. `AutoUpdateJob` and `PinDriftHandler` run their work inside `AuditOrigin::during(job|listener)`, so every row written along the way, by either installer, carries it; outside that, a command-line process (`occ`) is `cli` and a request `web`. `AuditLogger::record()` stamps it on every row, and an `AuditSubject` may name one explicitly. PHPUnit covers each origin and the restore after a throw; the e2e run of the job is left with the rest of the e2e work.
- [x] 1.3 Show the origin in `HistoryPanel.vue` and add the filter. Verify: a vitest spec with one row per origin and an old row with none.
  - History shows an Origin column (Automatic, Command line, Admin page, Drift check, Unknown for older rows) and an Origin filter; `GET /api/audit?origin=` takes `web`, `cli`, `job`, `listener` or `unknown` (rows without one). `src/components/HistoryPanel.spec.ts`, `AuditEntryMapperTest`, `ApiControllerBranchesTest::testTheAuditPageFiltersOnAKnownOrigin`.

## 2. Rule changes

- [x] 2.1 Audit `setPolicy`, `clearPolicy`, `updateAutoUpdateSettings` and `updateAdvisorySettings` with before and after. Verify: `tests/unit/Controller/ApiTest.php` asserts one row per write with the expected message.
  - `RuleChangeAuditor` writes `policy_set`, `policy_clear`, `auto_update_settings` and `advisory_settings` rows as `field: before -> after` for the fields that changed; a refused call, an unchanged setting or a clear of a policy that did not exist writes none. `tests/unit/Controller/ApiRuleChangeAuditTest.php` (one row per write) and `tests/unit/Service/Audit/RuleChangeAuditorTest.php`.
- [x] 2.2 Show "Set by {user} on {date}" on the policy selector. Verify: `PolicySelector.spec.ts`.
  - Shown under an active policy and as the selector's tooltip; `src/components/PolicySelector.spec.ts`.

## 3. Forwarding

- [x] 3.1 Dispatch `CriticalActionPerformedEvent` after each insert, with the sanitised message. Verify: `tests/unit/Service/Audit/AuditLoggerTest.php` asserts the event and that a token in a message is redacted in it.
  - `AuditLogger` dispatches `CriticalActionPerformedEvent` after each successful insert with the sanitised message (a token in a message reaches the event redacted), and never when the row was not written; a failing listener is logged and never reaches the operation. `AuditOriginAndForwardingTest`.
- [x] 3.2 Add the activity provider and setting, register them in `info.xml`, and publish one event per admin. Verify: unit tests of the provider's rendering and of a failing activity manager (install result unchanged); on a test instance the admin's activity stream shows the install.
  - `lib/Activity/Provider.php` renders install, rollback (a lower target version), pin, unpin, policy set and policy cleared; `lib/Activity/Setting.php` is on for the stream and off for mail; both are registered under `<activity>` in `info.xml`. `ActivityPublisher` publishes one event per admin after the row is written, only for a successful version change; a failing activity manager is logged and the row and the operation are unchanged. `tests/unit/Activity/ProviderTest.php`, `tests/unit/Service/Audit/ActivityPublisherTest.php`. The check on a live instance is left for the test-instance pass.

## 4. Close

- [ ] 4.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `aud-auto-attributed`, `aud-log-policy`, `aud-siem` and `aud-activity-stream` to `built` with evidence lines, then archive this change.
  - Strings for the origin column and filter, the policy setter and the activity provider are in `l10n/en` and `l10n/nl` (`npm run check:l10n-js` passes), and the four matrix rows are `built` with evidence lines. Left: archiving the change, which follows the merge.
