# advisories-bundled-libraries tasks

## 1. Reading and asking

- [ ] 1.1 Add `BundledLibraryScanner::scan()` per design D1. Verify: `tests/unit/Service/Advisory/BundledLibraryScannerTest.php` with committed `installed.json` fixtures covers the Composer 2 and Composer 1 layouts, `dev-package-names`, a `dev-main` version, a leading `v`, and a missing file.
- [ ] 1.2 Add `OsvClient` (query batch with `next_page_token`, vulnerability details, first fixed version, the SSRF guard, the https-only base override) per D2. Verify: `tests/unit/Service/Advisory/OsvClientTest.php` with a stubbed `IClientService` covers one batch for two apps sharing a library, a paged result, a record without `database_specific.severity` (`unknown`), and a 503 returned as an error.

## 2. Snapshot and job

- [ ] 2.1 Add `LibraryAdvisoryStore` and `LibraryAdvisoryJob` (daily, off while the switch is off, 120 s and 500-call limits) per D3, and register the job in `appinfo/info.xml`. Verify: `tests/unit/BackgroundJob/LibraryAdvisoryJobTest.php` asserts no call with the switch off, a saved snapshot with the switch on, and the previous snapshot kept when OSV fails.
- [ ] 2.2 Add `advisory.libraries_enabled` to `AdvisorySettingsStore` and the advisory settings routes, and `advisory.osv_api_base` to `InstanceSettings`. Verify: `ApiTest` round-trips the switch; `InstanceSettingsTest` refuses an `http://` base.
- [ ] 2.3 Assert that library findings leave the app's `state` untouched. Verify: `AdvisoryServiceTest` with a stored library finding for an app with no app advisory reads state `none`.

## 3. Page and connection

- [ ] 3.1 Return `libraries` and `librariesCheckedAt` from `GET /api/advisories`, and add `BundledLibrariesSection.vue`, the card badge and the switch text per D5. Verify: `src/components/BundledLibrariesSection.spec.ts` covers a finding, a clean app, an app without a manifest and the JavaScript line.
- [ ] 3.2 Add the `osv` row to `lib/Settings/connections.json` and report each run. Verify: `ConnectionReportServiceTest` asserts the report.
- [ ] 3.3 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 3.4 Give the fixture app a `vendor/composer/installed.json` with one library in `build-artifacts.sh`, add an OSV double (`/v1/querybatch`, `/v1/vulns/{id}`) to `tests/e2e/fixtures/forge/server.mjs`, and add `tests/e2e/bundled-libraries.spec.ts`: point `advisory.osv_api_base` at the fixture, switch the check on, `runJob("LibraryAdvisoryJob")`, and see the finding with its fixed version under the fixture app on the Advisories tab. Verify: the spec passes in the Playwright job.

## 4. Close

- [ ] 4.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 4.2 Set the matrix rows `adv-osv-feed` and `adv-dependencies` to `built` with evidence lines, then archive this change.
