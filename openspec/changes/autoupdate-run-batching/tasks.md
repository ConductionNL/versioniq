# autoupdate-run-batching tasks

## 1. Planning

- [ ] 1.1 Add `AutoUpdatePlan` and `AutoUpdatePlanner::plan()` per design D1, and make `AutoUpdateJob::run()` plan first and then install. Verify: every existing `tests/unit/BackgroundJob/AutoUpdateJobTest.php` case passes unchanged, plus `tests/unit/Service/AutoUpdate/AutoUpdatePlannerTest.php` for the `pinned`, `no_candidate`, `source_error` and `blocked` reasons.

## 2. Groups

- [ ] 2.1 Add `UpdateGroup` and `UpdateGroupStore` per D2. Verify: `tests/unit/Service/AutoUpdate/UpdateGroupStoreTest.php` round-trips a group and reads malformed JSON as no groups.
- [ ] 2.2 Apply the group rules of D3 in the planner and the job: wait whole, install in order, stop at the first failure, "not attempted" for the rest, message "Update group {name}". Verify: `AutoUpdateJobTest` covers a pinned member holding the group, a failed second member stopping the third, and the next run holding the group as `blocked`.
- [ ] 2.3 Add the `auto_update_group` notification in `AutoUpdateNotifier` and render it in `Notifier::prepare()`. Verify: `tests/unit/Notification/NotifierTest.php` renders a three-member outcome list; `AutoUpdateNotifierTest` asserts no per-app notification for group members.
- [ ] 2.4 Add `PUT /api/auto-update/groups` and return groups from `GET /api/policies`. Verify: `tests/unit/Controller/ApiTest.php` asserts 403 for a non-admin, and 400 for a duplicate name, an app in two groups and a group of one.

## 3. Caps

- [ ] 3.1 Add `AttemptLedger::countSince()`. Verify: `AttemptLedgerTest` counts attempts across two apps inside and outside seven days.
- [ ] 3.2 Add `max_per_run` and `max_per_week` to the settings store and `PUT /api/auto-update/settings`, 1 to 50 and 1 to 200, empty is off. Verify: `ApiTest` refuses 0 and 51 with 400 and stores 5.
- [ ] 3.3 Apply the caps and the waiting list of D4 and D5 in the planner. Verify: `AutoUpdatePlannerTest` covers a cap of 2 with three apps, a group of three that does not fit a remaining budget of 2, and a held app going first in the next run.

## 4. Page

- [ ] 4.1 Add `src/components/UpdateGroupsPanel.vue`, the two limit fields and the overview lists. Verify: `src/components/UpdateGroupsPanel.spec.ts` (add, reorder, save payload) and an extended `AutoUpdateOverview.spec.ts` (a blocked group, a waiting unit).
- [ ] 4.2 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 4.3 Extend `tests/e2e/auto-update.spec.ts`: an admin creates a group of two apps, sets a per-run limit of 3, reloads, and sees both. Verify: the spec passes in the Playwright job.
- [ ] 4.4 Add a second fixture app, `fixtureapp2`, to `tests/e2e/fixtures/forge/build-artifacts.sh` and `server.mjs`, with the same release line as `fixtureapp`. Verify: `bootstrap.sh` installs both on the CI instance.
- [ ] 4.5 Extend `tests/e2e/jobs.spec.ts` with a group of both fixture apps: `runJob("AutoUpdateJob")` installs both in order; a forced 404 on the first member's asset leaves the second untouched; a pin on one holds both; with a per-run limit of 1 and no group, one installs and the next run installs the other. Verify: the spec passes in the Playwright job.

## 5. Close

- [ ] 5.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 5.2 Set the matrix rows `aut-grouping` and `aut-rate-limit` to `built` with evidence lines, then archive this change.
