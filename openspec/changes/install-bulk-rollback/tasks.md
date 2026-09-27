# install-bulk-rollback tasks

## 1. Snapshots

- [ ] 1.1 Add `lib/Service/Snapshot/InstanceSnapshot.php` and `lib/Service/Snapshot/SnapshotStore.php` (design D1): lazy keys, the index, the 60 and 20 caps, named snapshots kept. Verify: `tests/unit/Service/Snapshot/SnapshotStoreTest.php` covers write and read, pruning of unnamed ones only, the named cap, and a malformed value read as absent.
- [ ] 1.2 Add `lib/BackgroundJob/SnapshotJob.php` (24 hours) in `appinfo/info.xml`, the snapshot before the first install in `AutoUpdateJob`, and the audit operation `snapshot` (design D2). Verify: `tests/unit/BackgroundJob/SnapshotJobTest.php`; `tests/unit/BackgroundJob/AutoUpdateJobTest.php` asserts one snapshot before the first install and none for a sweep that installs nothing.
- [ ] 1.3 Add `lib/Controller/SnapshotController.php`: `GET /api/snapshots` (with `at`), `POST /api/snapshots`, `DELETE /api/snapshots/{id}`, admin-only, writes password-confirmed. Verify: `tests/unit/Controller/SnapshotControllerTest.php` asserts 403, the date pick, and the audit row.

## 2. Plan

- [ ] 2.1 Add `lib/Service/Snapshot/RollbackPlanner.php` and `GET /api/snapshots/{id}/plan` (design D3). Verify: `tests/unit/Service/Snapshot/RollbackPlannerTest.php` covers each step kind, the order by newest audit row, and apps without audit rows last in alphabetical order.
- [ ] 2.2 Add `POST /api/snapshots/{id}/run-log` with the audit operation `bulk_rollback`. Verify: a controller test for the start and end rows with their counts.

## 3. Page

- [ ] 3.1 Add `src/components/RollbackPanel.vue` on the History tab and `src/dialogs/RollbackPlanDialog.vue` with Check and Run (design D4), running steps with `src/utils/updateBatch.ts` from `install-one-click-updates` and a stop-at-first-failure option. Verify: `src/dialogs/RollbackPlanDialog.spec.ts` covers the plan table, the check results, a stop on failure with "Continue with the rest", and the pinned tick; `updateBatch.spec.ts` gains the stop-on-failure mode.
- [ ] 3.2 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.3 Add `tests/e2e/rollback.spec.ts`: install two fixture versions from the forge fixture, take a snapshot between them, and roll back; assert the versions and the two `bulk_rollback` rows in the History tab.

## 4. Commands

- [ ] 4.1 Add `lib/Command/TakeSnapshot.php`, `lib/Command/ListSnapshots.php` and `lib/Command/RollbackToSnapshot.php` (design D5), registered in `appinfo/info.xml`. Verify: `tests/unit/Command/RollbackToSnapshotTest.php` covers `--to`, `--dry-run`, `--continue`, `--move-pins` and the exit code of a failed step; extend `tests/e2e/cli.spec.ts` with a snapshot and a dry-run rollback.

## 5. Close

- [ ] 5.1 Set the matrix row `ins-bulk-rollback` to `built` with an evidence line, then archive this change.
