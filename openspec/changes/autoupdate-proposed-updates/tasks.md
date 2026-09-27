# autoupdate-proposed-updates tasks

## 1. Proposals

- [ ] 1.1 Add `ProposedUpdate` and `ProposalStore` per design D1. Verify: `tests/unit/Service/AutoUpdate/ProposalStoreTest.php` round-trips a snapshot and keeps the previous one when encoding fails.
- [ ] 1.2 Add `ProposalService::refresh()` fed by the availability sweep's version lists, with the D2 rule for announced proposals, and call it from `AvailabilityRefreshJob` after the sweep is saved. Verify: `tests/unit/Service/AutoUpdate/ProposalServiceTest.php` asserts no source call, a `planned` proposal moving to a newer version, an announced one keeping its version, and planner reasons stored for apps without a proposal.
- [ ] 1.3 Add `GET /api/auto-update/proposals`, admin-only, reading the store only. Verify: `tests/unit/Controller/ApiTest.php` asserts 403 for a non-admin and the stored shape with `computedAt`.

## 2. Approval and declines

- [ ] 2.1 Add `requireApproval` to `Policy`, `PUT /api/app/{appId}/policy` and `GET /api/policies`, and the checkbox to `PolicySelector.vue`. Verify: `PolicyTest` round-trips it and reads its absence as false; `PolicySelector.spec.ts` emits it.
- [ ] 2.2 Add the approve, decline and withdraw routes per D4 with their audit entries. Verify: `ApiTest` asserts password confirmation, 404 for a version with no proposal, and one audit entry per call with the version in `to_version`.
- [ ] 2.3 Make the job honour declines and approval per D6. Verify: `AutoUpdateJobTest` covers an app requiring approval with 2.3.4 approved and 2.3.4 installed, with 2.3.4 approved while the plan says 2.3.5 (nothing installed), and a declined version skipped.

## 3. Notice

- [ ] 3.1 Add `notice_days` (0 to 60) and `notice_group` to the settings store and `PUT /api/auto-update/settings`. Verify: `ApiTest` refuses 61 and an unknown group with 400.
- [ ] 3.2 Send `auto_update_planned` on a new proposal, set `announcedAt` and `plannedFor`, and render it in `Notifier::prepare()` with the release notes excerpt. Verify: `AutoUpdateNotifierTest` asserts admins plus group members as recipients and no repeat for the same version; `NotifierTest` renders the planned date and notes.
- [ ] 3.3 Make the job wait until `plannedFor`, and plan `next_window` security fixes without the notice period. Verify: `AutoUpdateJobTest` covers an opening before and after `plannedFor`, and a critical fix installed in the next opening.

## 4. Page and command

- [ ] 4.1 Add `src/components/ProposedUpdatesPanel.vue` on the Apps tab with status, planned date, release notes, reasons, and Approve and Decline buttons. Verify: `src/components/ProposedUpdatesPanel.spec.ts` covers each status and the approve payload.
- [ ] 4.2 Add `lib/Command/AutoUpdate.php` (`versioniq:auto-update --dry-run [--json]`) per D3 and register it in `appinfo/info.xml`. Verify: `tests/unit/Command/AutoUpdateTest.php` asserts no install, no ledger write and no notification, the JSON keys, and exit code 1 with the switch off; extend `tests/e2e/cli.spec.ts` with a `--dry-run --json` run that leaves the fixture app at 1.0.0, and a run with automatic updates off that exits 1.
- [ ] 4.3 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 4.4 Add `tests/e2e/proposed-updates.spec.ts`: with the forge fixture app on `patch` requiring approval, `runJob("AvailabilityRefreshJob")`, then see the proposal 1.0.0 to 1.0.1 with its release notes, approve it on the page and see "Approved by admin"; pin the app and see the reason `pinned`. Verify: the spec passes in the Playwright job.
- [ ] 4.5 Extend `tests/e2e/jobs.spec.ts`: an approved 1.0.1 installs; with an extra `v1.0.2` tag added through `/control/repo` after approving 1.0.1, nothing installs; a declined 1.0.1 is skipped; with a 14-day notice period and a notice group holding a user added with `occ user:add`, the sweep writes `auto_update_planned` rows for the admin and that user, and `runJob("AutoUpdateJob")` leaves 1.0.0 in place. Verify: the spec passes in the Playwright job.

## 5. Close

- [ ] 5.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 5.2 Set the matrix rows `aut-approval`, `aut-dry-run` and `aut-advance-notice` to `built` with evidence lines, then archive this change.
