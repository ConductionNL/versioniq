# advisories-per-app-detail tasks

## 1. Richer records

- [ ] 1.1 Keep `cveIds`, `publishedAt` and `url` in `NextcloudAdvisoryFeed`, `ForgeReleaseSource::normalizeAdvisory()` and `AppStoreSource::listAdvisories()` per design D1. Verify: `NextcloudAdvisoryFeedTest` and `ForgeReleaseSourceTest` read them from a GHSA-shaped record, and leave them empty when absent.
- [ ] 1.2 Keep them in `summarise()` and add `history` with `affectsInstalled`, newest first, to every row from `evaluate()`. Verify: `AdvisoryServiceTest` asserts the order, the flag on an affected and an unaffected advisory, and that `advisories` is unchanged for both states.

## 2. Fix status

- [ ] 2.1 Add `FixStatus::for()` per D2 and set `fixStatus` on affected rows from the version list `correlate()` fetched. Verify: `tests/unit/Service/Advisory/FixStatusTest.php` covers `installable`, `no_fix_published`, `not_in_source` and `needs_newer_server`, and the server row.
- [ ] 2.2 Add `heldByPin` at read time in `GET /api/advisories`. Verify: `ApiTest` asserts it for a pin below an installable fix.

## 3. Compliance

- [ ] 3.1 Add `ComplianceStatus::from()` per D4 and return `compliance` from `GET /api/advisories`. Verify: `tests/unit/Service/Advisory/ComplianceStatusTest.php` covers never checked, stale at twice the interval plus one second, unreached, not compliant with a count per severity, and compliant.

## 4. Page

- [ ] 4.1 Render `history` with the five-most-recent limit, CVE ids, dates, links, the affects-or-fixed label and the fix sentence in `AdvisoriesPanel.vue`, with the `section-advisories-{appId}` anchors. Verify: `src/components/AdvisoriesPanel.spec.ts` covers ordering, "Show all", a missing date and each fix sentence.
- [ ] 4.2 Make the card badge a link to the app's anchor and teach `tabForHash()` the prefix; show the compliance line on the Apps and Advisories tabs. Verify: `src/utils/connectionRegistry.spec.ts` resolves the new prefix; `src/utils/advisories.spec.ts` covers the compliance sentence for each status.
- [ ] 4.3 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 4.4 Extend `tests/e2e/advisories.spec.ts`: with the forge fixture advisory affecting `fixtureapp` 1.0.0, run `runJob("AdvisoryRefreshJob")`, see "Not compliant" on the Apps tab, follow the card badge to the app's list on the Advisories tab, and see the CVE id and date; then install 1.0.1, sweep again and see "Compliant"; finally set a fixture advisory with no patched version covering every release and see "No fixed version is published yet". Verify: the spec passes in the Playwright job.

## 5. Command

- [ ] 5.1 Add `lib/Command/ListAdvisories.php` (`versioniq:advisories`, `--json`, `--app`, `--check`) per D5 and register it. Verify: `tests/unit/Command/ListAdvisoriesTest.php` covers the three `--check` exit codes and the JSON keys; extend `tests/e2e/cli.spec.ts` with `--check` exiting 0 on a compliant fixture instance and 2 after `occ config:app:delete versioniq advisory.results`.

## 6. Close

- [ ] 6.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 6.2 Set the matrix rows `adv-cve-history`, `adv-unfixable-reason` and `aud-patch-compliance` to `built` with evidence lines, then archive this change.
