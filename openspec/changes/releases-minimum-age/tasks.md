# releases-minimum-age tasks

## 1. Age rule

- [ ] 1.1 Add `auto_update_min_age_days` to `AutoUpdateSettingsStore` and the optional `minAgeDays` to `Policy` (design D1), accepted by `PUT /api/auto-update/settings` and `PUT /api/app/{appId}/policy`. Verify: `tests/unit/Service/AutoUpdate/AutoUpdateSettingsStoreTest.php` rejects 91 and -1; a `Policy` test round-trips a policy with and without `minAgeDays`; `tests/unit/Controller/ApiTest.php` asserts 400 on an out-of-range value.
- [ ] 1.2 Add `lib/Service/AutoUpdate/FirstSeenStore.php` and `lib/Service/AutoUpdate/MinimumAgeRule.php` (design D2). Verify: `tests/unit/Service/AutoUpdate/MinimumAgeRuleTest.php` covers a dated version, an undated one first seen days ago, an undated one seen now, age 0, and pruning of versions no longer listed.

## 2. Planner and job

- [ ] 2.1 Add `lib/Service/AutoUpdate/AutoUpdatePlanner.php` (design D3, D5, D6). Verify: `tests/unit/Service/AutoUpdate/AutoUpdatePlannerTest.php` covers the newest eligible version taken while a newer one waits, nothing eligible, an exception, each of the three exception clean-ups, a security bypass inside the level, one outside the level, and newer versions still waiting after a bypass.
- [ ] 2.2 Make `AutoUpdateJob::processApp()` install the planner's `next`, and add the security note to the audit message and to `AutoUpdateNotifier` and `Notifier`. Verify: `tests/unit/BackgroundJob/AutoUpdateJobTest.php` covers a held version not installed and a bypassed one installed with the note; `tests/unit/Notification/NotifierTest.php` renders the note.
- [ ] 2.3 Add `lib/Service/AutoUpdate/AutoUpdatePlanStore.php` and `lib/BackgroundJob/AutoUpdatePlanJob.php` (6 hours, 600 s budget), registered in `appinfo/info.xml` (design D4). Verify: `tests/unit/BackgroundJob/AutoUpdatePlanJobTest.php` covers a run with the kill switch off, a pinned app without a source call, one failing app not stopping the rest, and the previous snapshot kept on an encoding failure.

## 3. Endpoints and page

- [ ] 3.1 Return each app's plan and `checkedAt` from `GET /api/policies`; add `POST` and `DELETE /api/app/{appId}/min-age-exception/{version}`, admin-only, password-confirmed, audited. Verify: `tests/unit/Controller/ApiTest.php` asserts 403, the plan shape, and one audit row per exception change.
- [ ] 3.2 Show the minimum age on the Settings row next to the window and in `PolicySelector.vue`; show "Next update", held versions with their dates, "Release now" and the plan time in `AutoUpdateOverview.vue`. Verify: `src/components/AutoUpdateOverview.spec.ts` covers a held version, an exception, no plan yet, and "No update qualifies"; `src/components/PolicySelector.spec.ts` saves `minAgeDays`.
- [ ] 3.3 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.4 Extend `tests/e2e/auto-update.spec.ts`: set a minimum age, run the plan job with `occ background-job:execute --force-execute` as `tests/e2e/jobs.spec.ts` does, and assert the held version and its date in the overview.

## 4. Close

- [ ] 4.1 Set the matrix rows `rel-min-age`, `rel-held-back-visible`, `rel-min-age-exception` and `rel-security-bypass-min-age` to `built` with evidence lines, then archive this change.
