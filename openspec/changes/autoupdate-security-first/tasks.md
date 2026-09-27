# autoupdate-security-first tasks

## 1. The security level

- [ ] 1.1 Add `Policy::LEVEL_SECURITY` to `VALID_LEVELS`, and the option "Security fixes only" to `PolicySelector.vue`. Verify: `tests/unit/Service/Policy/PolicyTest.php` accepts `security`; `tests/unit/Controller/ApiTest.php` stores it via `PUT /api/app/{appId}/policy`; `PolicySelector.spec.ts` renders the option.
- [ ] 1.2 Add `lib/Service/AutoUpdate/SecurityCandidate.php` per design D1 and D2. Verify: `tests/unit/Service/AutoUpdate/SecurityCandidateTest.php` covers each reason: `not_affected`, `no_fix`, `needs_major`, `not_in_source`, `stale`, and the happy path.
- [ ] 1.3 In `AutoUpdateJob`, read the advisory snapshot once per run and install `security` apps through `SecurityCandidate`. Verify: `AutoUpdateJobTest` installs the recommended 2.3.5 for an affected app on `security`, installs nothing for an unaffected one, and still skips a pinned affected app without a source query.

## 2. Patch policy

- [ ] 2.1 Add `PatchPolicy` and `PatchPolicyStore` per D3, with the no-policy reading equal to today's behaviour. Verify: `tests/unit/Service/Policy/PatchPolicyStoreTest.php` round-trips the example and reads malformed JSON as no policy.
- [ ] 2.2 Add `GET` and `PUT /api/patch-policy`, admin-only, `PUT` password-confirmed, validating everything before writing, and writing a `patch_policy` audit entry on change. Verify: `ApiTest` asserts 403 for a non-admin, 400 for `deadlineDays` 0 and 366 and an unknown handling, nothing written on 400, and one audit entry on 200.
- [ ] 2.3 Render the statement lines with `IL10N` per D5. Verify: a unit test asserts the sentence for a critical `next_window` row with 3 days and for a `manual` major.
- [ ] 2.4 Add `src/components/PatchPolicyPanel.vue` on the Settings tab: form, statement, last change, text download. Verify: `src/components/PatchPolicyPanel.spec.ts` covers the form payload and the downloaded text equal to the shown lines.

## 3. Security first

- [ ] 3.1 Order the run in two passes per D4, with `next_window`, `scheduled` and `manual` handling, and `manual` regular kinds skipped. Verify: `AutoUpdateJobTest` covers an affected `minor` app installed before an unaffected one, a `manual` critical left alone, and a `manual` major not installed for an `all` app.
- [ ] 3.2 Show, per `security` app with an affected version, the reason nothing installed, and the stale-snapshot line, in `AutoUpdateOverview.vue`. Verify: `AutoUpdateOverview.spec.ts` renders `needs_major` and `stale`.
- [ ] 3.3 Assert that `AdvisoryNotifier` and `AdvisoryRefreshJob` still take no installer or policy dependency. Verify: a reflection test in `tests/unit/BackgroundJob/AdvisoryRefreshJobTest.php` on both constructors.

## 4. Page and strings

- [ ] 4.1 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 4.2 Add `tests/e2e/patch-policy.spec.ts`: an admin saves a patch policy with a critical deadline of 3 days, reloads, and sees the sentence and their name; then sets one app to "Security fixes only". Verify: the spec passes in the Playwright job.
- [ ] 4.3 Extend `tests/e2e/jobs.spec.ts` with a fixture advisory (`/control/repo` advisories) that affects `fixtureapp` 1.0.0 and is patched in 1.0.1: after `runJob("AdvisoryRefreshJob")` the version is unchanged; with no policy, `runJob("AutoUpdateJob")` still changes nothing; on level `security`, it installs 1.0.1; without the advisory, level `security` installs nothing; with patch updates set to by hand and level `patch`, nothing installs; with a critical advisory, `next_window` and minors not due today, level `minor` installs the fix. Verify: the spec passes in the Playwright job.

## 5. Close

- [ ] 5.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 5.2 Set the matrix rows `aut-security-only` and `aud-patch-policy` to `built` with evidence lines, then archive this change.
