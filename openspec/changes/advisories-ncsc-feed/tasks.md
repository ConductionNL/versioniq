# advisories-ncsc-feed tasks

## 1. Reader

- [ ] 1.1 Add `lib/Service/Advisory/VersRange.php` per design D4. Verify: `tests/unit/Service/Advisory/VersRangeTest.php` covers `<`, `>=` with `|`, `*`, `!=`, `vers:unknown/*` (null) and garbage (null).
- [ ] 1.2 Add `lib/Service/Advisory/CsafProductMatcher.php` per D3. Verify: `tests/unit/Service/Advisory/CsafProductMatcherTest.php` with committed CSAF fixtures resolves "Nextcloud Server" to `:server`, "Nextcloud Talk" to `spreed`, drops a desktop client and a non-Nextcloud vendor, and ignores a product no vulnerability lists as known affected.
- [ ] 1.3 Add `lib/Service/Advisory/NcscAdvisoryFeed.php` with the cursor, the 365-day first read, the 300-document and 120 s limits, the index and the error handling of D1 and D2. Verify: `tests/unit/Service/Advisory/NcscAdvisoryFeedTest.php` with a stubbed `IClientService` asserts only newer lines are fetched, the cursor stops at the last processed document when the limit hits, a changed document replaces its index entry, and a 500 on `changes.csv` returns the index with an error.

## 2. Correlation

- [ ] 2.1 Merge NCSC-NL records into `AdvisoryService::correlateAll()` when enabled, with the `affected`, `not_affected` and `version_not_stated` handling in `evaluate()` per D4. Verify: `AdvisoryServiceTest` asserts a `version_not_stated` record leaves state `advisory-available`, an `affected` one gives `pinned-to-vulnerable`, and the switch off means no NCSC-NL call.
- [ ] 2.2 Merge records that share a CVE per D5, reading `cve_id` from the GHSA record. Verify: `AdvisoryServiceTest` asserts one entry with both ids and no double count.
- [ ] 2.3 Store severity from CVSS and the two ratings per D6. Verify: `NcscAdvisoryFeedTest` asserts `high` from a CVSS base severity of HIGH, `unknown` without scores, and the `Kans` and `Schade` values kept as given.

## 3. Setting, display and connection

- [ ] 3.1 Add `advisory.ncsc_enabled` to `AdvisorySettingsStore` and `GET` and `PUT /api/advisory/settings`, and `advisory.ncsc_feed_base` (https only) to `InstanceSettings`. Verify: `ApiTest` round-trips the switch; `InstanceSettingsTest` refuses an `http://` base.
- [ ] 3.2 Show NCSC-NL records on the Advisories tab per D7, with the "not stated" line and the catching-up line. Verify: `src/components/AdvisoriesPanel.spec.ts` renders an NCSC-NL record with both ratings and a merged record with both ids.
- [ ] 3.3 Add the `ncsc` row to `lib/Settings/connections.json` and report each read. Verify: `tests/unit/Service/Connection/ConnectionReportServiceTest.php` asserts the report with read and matched counts.
- [ ] 3.4 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 3.5 Serve a CSAF directory from the fixture forge (`tests/e2e/fixtures/forge/server.mjs`: a `changes.csv` and documents about Nextcloud Server, one with a version range covering the CI server and one with `vers:unknown/*`, switchable through `/control`) and extend `tests/e2e/advisories.spec.ts`: switch NCSC-NL on, `runJob("AdvisoryRefreshJob")`, and see the affected advisory and the "does not state which versions" advisory in the Nextcloud server block. Verify: the spec passes in the Playwright job.

## 4. Close

- [ ] 4.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 4.2 Set the matrix row `adv-ncsc` to `built` with evidence lines, then archive this change.
