# pinning-ranges-and-skips tasks

## 1. Range guard

- [ ] 1.1 Add `holdManual` to `Policy` and `PolicyStore`, and the switch to `PolicySelector.vue` with its help text. Verify: `tests/unit/Service/Policy/PolicyTest.php` reads an old policy as `holdManual` false; `PolicySelector.spec.ts` saves the switch.
- [ ] 1.2 Add the `outOfRange` guard and the `overrideRange` flag to `installAppVersion()`, and handle the 409 in the picker. Verify: `tests/unit/Service/InstallerServiceTest.php` for an in-range install, an out-of-range refusal, an override with its audit row, and a pinned app hitting the pin guard first.

## 2. Skip list

- [ ] 2.1 Add `SkipList`, the two routes and the audit operations. Verify: `tests/unit/Service/Pin/SkipListTest.php` (cap, idempotent skip) and `tests/unit/Controller/ApiTest.php` (403 for a non-admin).
- [ ] 2.2 Drop skipped versions in `CandidateSelector` and in the availability sweep. Verify: `tests/unit/Service/AutoUpdate/CandidateSelectorTest.php` picks 2.3.5 when 2.3.4 is skipped.
- [ ] 2.3 Add Skip and Unskip to the picker and the update badge, and the confirmation for installing a skipped version. Verify: vitest specs; `tests/e2e/skip-version.spec.ts` skips a fixture version and sees the next one offered.
- [ ] 2.4 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.

## 3. Close

- [ ] 3.1 Set the matrix rows `pin-range` and `pin-ignore-version` to `built` with evidence lines, then archive this change.
