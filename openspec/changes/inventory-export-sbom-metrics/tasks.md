# inventory-export-sbom-metrics tasks

## 1. Inventory and formats

- [x] 1.1 Add `InventoryBuilder` (server plus installed apps, licence, source, recorded SHA-256). Verify: `tests/unit/Service/Inventory/InventoryBuilderTest.php` with a shipped, an App Store and a GitHub-bound app.
- [x] 1.2 Add `SbomWriter` for CycloneDX 1.5 and SPDX 2.3 JSON with the purl rules of design D2. Verify: unit tests validate both outputs against the published JSON schemas (vendored under `tests/fixtures/sbom/`), and a GitHub-bound app gets `pkg:github/...`.
  - `tests/unit/Service/Inventory/SbomSchemaTest.php` validates both documents with opis/json-schema against CycloneDX 1.5 (with the SPDX licence and JSF schemas it references) and SPDX 2.3, copied unchanged into `tests/fixtures/sbom/` (licences in `REUSE.toml`), and checks the schemas refuse a broken document.

## 2. Export

- [x] 2.1 Add `GET /api/sbom?format=` and `occ versioniq:sbom`. Verify: `tests/unit/Controller/ApiTest.php` (403 for a non-admin, a download for an admin) and `tests/unit/Command/ExportSbomTest.php`.
  - `SbomExporter` builds the document (instance id, time, a version 4 UUID); `SbomController` serves `GET /api/sbom?format=` as a download for admins (403 otherwise, 400 for another format), and `occ versioniq:sbom --format= [--output=]` prints or writes it. The route is in `openapi.json`. `SbomControllerTest`, `ExportSbomTest`, `SbomExporterTest`.
- [x] 2.2 Add the Export SBOM menu to `HistoryPanel.vue`. Verify: a vitest spec that both menu items call the right URL.
  - `src/components/SbomExportMenu.vue` (CycloneDX 1.5 and SPDX 2.3 buttons) sits at the top of the History tab in `App.vue`, beside `HistoryPanel` rather than inside it, so it stays clear of the other open History work. It fetches with the OCS header and saves the file. `src/components/SbomExportMenu.spec.ts` asserts both buttons call `/api/sbom?format=`.

## 3. A copy per update

- [x] 3.1 Add `SbomArchive` and call it after a successful real install, inside a try/catch. Verify: a unit test that a throwing archive leaves the install result unchanged, and one that a dry run writes nothing.
  - `SbomArchive::writeFor()` keeps a CycloneDX copy in app data folder `sbom` under the id of the app's newest successful install row (`AuditEntryMapper::findLatestSuccessfulInstall()`), pruned to `sbom.keep` (200); `InstallerService` calls it after a real install only, and nothing it throws reaches the result. `InstallerServiceSbomArchiveTest` (a throwing archive leaves the result identical, a dry run writes nothing), `SbomArchiveTest`, `AuditEntryMapperLatestInstallTest`.
- [x] 3.2 Add `sbomAvailable` to install rows of `GET /api/audit`, `GET /api/sbom/{id}`, and the per-row download link. Verify: `tests/e2e/sbom.spec.ts` installs a fixture version and downloads its SBOM from the History tab.
  - Install rows of `GET /api/audit` carry `sbomAvailable`; `GET /api/sbom/{id}` returns the kept copy (404 when none, 403 for a non-admin) and is in `openapi.json`; each install row with a copy gets an SBOM button. `ApiAuditSbomFlagTest`, `SbomControllerTest`, `src/components/HistoryPanel.sbom.spec.ts`. Left: `tests/e2e/sbom.spec.ts`, which needs the Playwright job.

## 4. Metrics

- [x] 4.1 Add `AppVersionMetricFamily` and the `<openmetrics>` entry, with a psalm stub for `OCP\OpenMetrics`. Verify: a unit test of the metric labels, and on a Nextcloud 33 test instance `curl /metrics` shows `nextcloud_versioniq_app_version`.
  - `lib/OpenMetrics/AppVersionMetricFamily.php`: a gauge of 1 per installed app with `app_id`, `version`, `shipped`, `source` and `behind` (`unknown` before the first sweep), listed under `<openmetrics>` in `info.xml`. No psalm stub was needed: `nextcloud/ocp` ^35 already ships the 33 interface. `tests/unit/OpenMetrics/AppVersionMetricFamilyTest.php`. Left: the `curl /metrics` check on a Nextcloud 33 test instance.

## 5. Close

- [ ] 5.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `inv-export-inventory`, `inv-sbom-per-update` and `inv-metrics-endpoint` to `built` with evidence lines, then archive this change.
  - Strings for the export menu and the row link are in `l10n/en` and `l10n/nl` (`npm run check:l10n-js` passes), and the three matrix rows are `built` with evidence lines. Left: archiving the change, after the merge.

## Build notes

Done in a first slice:
- 1.1: `lib/Service/Inventory/InventoryBuilder.php` reads the server version through `ServerVersionProvider` (`OCP\ServerVersion` is readonly and cannot be doubled) and every installed app with its licence, source and recorded SHA-256.
- 1.2's writer: `lib/Service/Inventory/SbomWriter.php` writes CycloneDX 1.5 and SPDX 2.3 JSON with the purl rules of D2 and the licence rules of D3. A licence that is not an SPDX id goes into an SPDX licence comment rather than an undefined `LicenseRef`, which would make the document invalid.

Second slice: 1.2's schema check, 2.1 to 3.2 and 4.1 are built (see each task). Left: the e2e check of 3.2, the live `/metrics` check of 4.1, and archiving.
