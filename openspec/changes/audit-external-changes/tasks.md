# audit-external-changes tasks

## 1. The record of what was seen

- [ ] 1.1 Add `lib/Service/Audit/SeenStateStore.php` (design D1) and update it from `InstallFinalizer::finalize()` after a successful install. Verify: `tests/unit/Service/Audit/SeenStateStoreTest.php` covers read, write, a malformed value read as empty, and the baseline flag; an `InstallFinalizer` test asserts the update after success and none after a failure.

## 2. Recording changes

- [ ] 2.1 Add `lib/Service/Audit/TriggerDescriber.php` and `lib/Service/Audit/ExternalChangeRecorder.php`, the operations `external_update`, `external_disable` and `reactivate` in `AuditLogger`, and call the recorder from `AppUpdatedListener` for every app (design D2). Verify: `tests/unit/Service/Audit/TriggerDescriberTest.php` covers a CLI command with `--password` redacted, a long command truncated, a web path without its query, and unknown; `tests/unit/Listener/AppUpdatedListenerTest.php` covers an unpinned app recorded, a pinned app recorded next to its drift, and an unchanged version not recorded.
- [ ] 2.2 Add `lib/BackgroundJob/ExternalChangeReconcileJob.php` (hourly) in `appinfo/info.xml` (design D3). Verify: `tests/unit/BackgroundJob/ExternalChangeReconcileJobTest.php` covers the baseline run, a changed version, an app added and removed, and a Versioniq install already in the record.

## 3. Disabled by an update

- [ ] 3.1 Add `lib/Listener/AppDisabledListener.php` for `AppDisableEvent`, registered in `Application::register()`, and `lib/Service/Reactivation/ReactivationService.php` with the `reactivate.auto` switch in `InstanceSettings` and the `app_left_disabled` subject in `Notifier` (design D4). Verify: `tests/unit/Service/Reactivation/ReactivationServiceTest.php` covers a compatible app with the switch on and off, an incompatible app, a deliberate disable, and a failed enable; `tests/unit/Notification/NotifierTest.php` renders both texts.
- [ ] 3.2 Show "Disabled by the update to {version}" on the card with Enable or the compatible version, the switch on the Settings tab, and readable labels for the new operations and the trigger in `src/components/HistoryPanel.vue`. Verify: vitest specs for the card notice, the settings switch and the history labels.
- [ ] 3.3 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.4 Extend `tests/e2e/jobs.spec.ts`: install fixture 1.0.1, then seed `external.seen` with 1.0.0 for the fixture app through `occ config:app:set` (a stale record, as the pin reconcile test seeds a stale pin, because an out-of-band version change puts the instance into Nextcloud's upgrade-required state), run the hourly job with `occ background-job:execute --force-execute`, and assert the `external_update` row from 1.0.0 to 1.0.1 with the hourly-check trigger; then `occ app:disable` the fixture app, run the job, and assert the `external_disable` row and that the app stays disabled.

## 4. Close

- [ ] 4.1 Set the matrix rows `aud-external-changes` and `saf-reactivate-after-update` to `built` with evidence lines, then archive this change.
