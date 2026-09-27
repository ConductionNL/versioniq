# inventory-export-sbom-metrics tasks

## 1. Inventory and formats

- [ ] 1.1 Add `InventoryBuilder` (server plus installed apps, licence, source, recorded SHA-256). Verify: `tests/unit/Service/Inventory/InventoryBuilderTest.php` with a shipped, an App Store and a GitHub-bound app.
- [ ] 1.2 Add `SbomWriter` for CycloneDX 1.5 and SPDX 2.3 JSON with the purl rules of design D2. Verify: unit tests validate both outputs against the published JSON schemas (vendored under `tests/fixtures/sbom/`), and a GitHub-bound app gets `pkg:github/...`.

## 2. Export

- [ ] 2.1 Add `GET /api/sbom?format=` and `occ versioniq:sbom`. Verify: `tests/unit/Controller/ApiTest.php` (403 for a non-admin, a download for an admin) and `tests/unit/Command/ExportSbomTest.php`.
- [ ] 2.2 Add the Export SBOM menu to `HistoryPanel.vue`. Verify: a vitest spec that both menu items call the right URL.

## 3. A copy per update

- [ ] 3.1 Add `SbomArchive` and call it after a successful real install, inside a try/catch. Verify: a unit test that a throwing archive leaves the install result unchanged, and one that a dry run writes nothing.
- [ ] 3.2 Add `sbomAvailable` to install rows of `GET /api/audit`, `GET /api/sbom/{id}`, and the per-row download link. Verify: `tests/e2e/sbom.spec.ts` installs a fixture version and downloads its SBOM from the History tab.

## 4. Metrics

- [ ] 4.1 Add `AppVersionMetricFamily` and the `<openmetrics>` entry, with a psalm stub for `OCP\OpenMetrics`. Verify: a unit test of the metric labels, and on a Nextcloud 33 test instance `curl /metrics` shows `nextcloud_versioniq_app_version`.

## 5. Close

- [ ] 5.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `inv-export-inventory`, `inv-sbom-per-update` and `inv-metrics-endpoint` to `built` with evidence lines, then archive this change.
