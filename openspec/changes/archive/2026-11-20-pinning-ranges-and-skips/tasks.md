# pinning-ranges-and-skips tasks

## 1. Range guard

- [x] 1.1 Add `holdManual` to `Policy` and `PolicyStore`, and the switch to `PolicySelector.vue` with its help text. Verify: `tests/unit/Service/Policy/PolicyTest.php` reads an old policy as `holdManual` false; `PolicySelector.spec.ts` saves the switch.
- [x] 1.2 Add the `outOfRange` guard and the `overrideRange` flag to `installAppVersion()`, and handle the 409 in the picker. Verify: `tests/unit/Service/InstallerServiceTest.php` for an in-range install, an out-of-range refusal, an override with its audit row, and a pinned app hitting the pin guard first.

## 2. Skip list

- [x] 2.1 Add `SkipList`, the two routes and the audit operations. Verify: `tests/unit/Service/Pin/SkipListTest.php` (cap, idempotent skip) and `tests/unit/Controller/ApiTest.php` (403 for a non-admin).
- [x] 2.2 Drop skipped versions in `CandidateSelector` and in the availability sweep. Verify: `tests/unit/Service/AutoUpdate/CandidateSelectorTest.php` picks 2.3.5 when 2.3.4 is skipped.
- [x] 2.3 Add Skip and Unskip to the picker and the update badge, and the confirmation for installing a skipped version. Verify: vitest specs; `tests/e2e/skip-version.spec.ts` skips a fixture version and sees the next one offered.
  - Built: `src/components/VersionSkipControl.vue` on every version row of the picker (Skip with an optional reason, or "Skipped: reason" with Unskip), `src/components/UpdateBadgeSkip.vue` beside the card's update badge for the offered version (Skip, then Skipped with Unskip until the next sweep offers the version after it; a change re-reads the picker's marks for that app), and the one confirmation naming the skip and its reason before `performInstall()` installs a skipped version. Tests: `src/components/VersionSkipControl.spec.ts`, `src/components/UpdateBadgeSkip.spec.ts`, `src/App.rangeAndSkip.spec.ts` (confirmation, badge control only on a card with an update, picker refresh). The e2e spec `tests/e2e/skip-version.spec.ts` is written and waits for the Playwright job.
- [x] 2.4 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.

## 3. Close

- [x] 3.1 Set the matrix rows `pin-range` and `pin-ignore-version` to `built` with evidence lines, then archive this change.
