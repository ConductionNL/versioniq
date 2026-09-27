# autoupdate-schedules tasks

## 1. Schedule model

- [ ] 1.1 Add `lib/Service/AutoUpdate/UpdateType.php` with `classify(string $installed, string $candidate)` returning `patch`, `minor` or `major` per design D1. Verify: `tests/unit/Service/AutoUpdate/UpdateTypeTest.php` covers the three kinds, a pre-release and a non-semver version (both `major`).
- [ ] 1.2 Add `lib/Service/AutoUpdate/AutoUpdateSchedule.php` with `fromArray()`, `toArray()`, `isValidArray()`, `dueKinds()` and `nextRun()` per D2, D3 and D5. Verify: `tests/unit/Service/AutoUpdate/AutoUpdateScheduleTest.php` covers the every-day default, weekdays, an empty list, `monthly` on day 7 and day 8, a midnight-crossing window opened on Friday, and `nextRun()` returning null for a kind with no days.
- [ ] 1.3 Add `AutoUpdateWindow::openedOn()` and make `openingKey()` use it. Verify: the existing `AutoUpdateWindowTest` cases still pass, plus one that `openedOn()` returns the previous date at 00:30 in a 23:00-03:00 window.
- [ ] 1.4 Add `getSchedule()` and `setSchedule()` to `AutoUpdateSettingsStore`, with a malformed value logged and read as the default. Verify: `AutoUpdateSettingsStoreTest` round-trips a schedule and reads malformed JSON as every day.

## 2. Job and selector

- [ ] 2.1 Give `CandidateSelector::select()` an optional due-kinds argument, defaulting to all three kinds. Verify: `CandidateSelectorTest` keeps every existing case green and adds: minor policy with 2.3.4 and 2.4.0 available picks 2.3.4 when only `patch` is due.
- [ ] 2.2 In `AutoUpdateJob::run()`, compute the due kinds from `openedOn()`, return after marking the opening swept when none is due, and pass them to the selector. Verify: `AutoUpdateJobTest` asserts no source query on a day with no kind due, and the patch install on a patch-only day.

## 3. API and page

- [ ] 3.1 Return `autoUpdateSchedule` and `nextRuns` from `GET /api/policies`, and accept and validate `schedule` in `PUT /api/auto-update/settings` per D6. Verify: `tests/unit/Controller/ApiTest.php` asserts 400 for day 8, for an unknown kind and for a string `monthly`, that nothing is written on 400, and the stored value on 200.
- [ ] 3.2 Add `src/components/AutoUpdateSchedule.vue` and wire it into the Automatic updates block in `App.vue`, saved with the switch and window. Verify: `src/components/AutoUpdateSchedule.spec.ts` covers the same-days mode, the per-kind mode and the monthly checkbox, and asserts the emitted JSON.
- [ ] 3.3 Show days and next run per kind in `AutoUpdateOverview.vue`, including "never automatically" and "nothing runs". Verify: extend `AutoUpdateOverview.spec.ts` with a weekday schedule and an empty one.
- [ ] 3.4 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 3.5 Extend `tests/e2e/auto-update.spec.ts`: an admin saves weekdays Mon to Fri for every kind, reloads, and sees them and the next run. Verify: the spec passes in the Playwright job.
- [ ] 3.6 Extend `tests/e2e/jobs.spec.ts` with the forge fixture app (1.0.0, with 1.0.1, 1.1.0 and 1.2.0 served): a schedule without today's weekday installs nothing; with today added, `runJob("AutoUpdateJob")` installs; policy `minor` with patches due today and minors not due installs 1.0.1, not 1.2.0. Verify: the spec passes in the Playwright job.

## 4. Close

- [ ] 4.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 4.2 Set the matrix rows `aut-schedule-days` and `aut-schedule-per-type` to `built` with evidence lines, then archive this change.
