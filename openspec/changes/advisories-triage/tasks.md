# advisories-triage tasks

## 1. Store and rules

- [ ] 1.1 Add `AdvisoryTriageStore` (read, write, `prune()` with `lastSeenAt`) per design D1. Verify: `tests/unit/Service/Advisory/AdvisoryTriageStoreTest.php` round-trips an entry for `:server` and for an app, reads malformed JSON as empty, and prunes an entry unseen for 31 days.
- [ ] 1.2 Add `AdvisoryTriage::apply()` per D2: active and lapsed dismissals (end date, version change), recomputed `state` and `recommendedVersion`. Verify: `tests/unit/Service/Advisory/AdvisoryTriageTest.php` covers each lapse, a row falling from `pinned-to-vulnerable` to `advisory-available`, and a row with one of two advisories dismissed.

## 2. Readers

- [ ] 2.1 Apply triage in `AdvisoryNotifier` and `AdvisoryDigestNotifier`. Verify: `AdvisoryNotifierTest` asserts no notification for a dismissed pair and none after a reopen; `AdvisoryDigestNotifierTest` counts only active advisories.
- [ ] 2.2 Add the `triage` map to `GET /api/advisories` and make the refresh job stamp and prune after saving. Verify: `ApiTest` asserts the map; `AdvisoryRefreshJobTest` asserts the prune runs after `save()`.

## 3. Routes

- [ ] 3.1 Add `AdvisoryTriageController` with dismiss, reopen and assign per D3 and D4, and the three audit operations. Verify: `tests/unit/Controller/AdvisoryTriageControllerTest.php` asserts 403 for a non-admin, 404 for an advisory not in the snapshot, 400 for `fix_planned` without `until` and for a non-admin user as assignee, and one audit entry per success.
- [ ] 3.2 Add the `advisory_assigned` notification to group admins or the user, and render it in `Notifier::prepare()`. Verify: `NotifierTest` renders it; the controller test asserts only admin members of a group are notified.

## 4. Page

- [ ] 4.1 Add `src/dialogs/DismissAdvisoryDialog.vue` and the Dismiss, Assign, Dismissed and Reopen controls and the "assigned to me" filter in `AdvisoriesPanel.vue`; make the card badge use active advisories only. Verify: `src/components/AdvisoriesPanel.spec.ts` covers a dismissed advisory moving to the Dismissed list and the filter; `src/utils/advisories.spec.ts` covers the badge without dismissed advisories.
- [ ] 4.2 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 4.3 Extend `tests/e2e/advisories.spec.ts` with the advisory fixture: an admin dismisses a server advisory as "not used" with a comment, sees it under Dismissed with their name, and reopens it. Verify: the spec passes in the Playwright job.
- [ ] 4.4 Extend `tests/e2e/advisories.spec.ts` with a fixture advisory on `fixtureapp` for versions below 1.1.0: dismiss it on 1.0.0, install 1.0.1, `runJob("AdvisoryRefreshJob")`, and see it counted again; then create a second admin with `occ user:add` and `occ group:adduser admin`, assign the advisory to them, assert their `advisory_assigned` notification row, and in a browser context logged in as them see it under "Assigned to me". Verify: the spec passes in the Playwright job.

## 5. Close

- [ ] 5.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 5.2 Set the matrix rows `adv-dismiss` and `adv-assign-owner` to `built` with evidence lines, then archive this change.
