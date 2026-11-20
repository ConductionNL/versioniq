# Design: inventory-export-sbom-metrics

Read against `development` at 02e1050 (2026-09-27).

## Context

- `InstallerService::getInstalledApps()` (`lib/Service/InstallerService.php:89-170`) returns id, name, installed version, `isShipped`, `isCore` and `boundSourceId` for every app on the Apps tab.
- `SourceBinding::getRecordedSha()` (`lib/Service/Source/SourceBinding.php:150`) holds the SHA-256 recorded on the first external install of a version.
- A real install succeeds in `InstallerService::installAppVersion()` in the `if (!$dryRun)` branch that writes the binding (`lib/Service/InstallerService.php:618-621`); the audit row for it is written by the installers (`SelectedReleaseInstallerService::recordInstallAudit()`, `lib/Service/SelectedReleaseInstallerService.php:437-455`).
- `ArtifactCache` (`lib/Service/Cache/ArtifactCache.php`) already keeps files in `IAppData`, one folder per purpose, so app data is the known place for generated files.
- Nextcloud 33 added OpenMetrics. `OC\OpenMetrics\ExporterManager` reads an `<openmetrics>` entry from each enabled app's `info.xml` and loads classes implementing `OCP\OpenMetrics\IMetricFamily` (server `lib/private/OpenMetrics/ExporterManager.php`, `lib/public/OpenMetrics/IMetricFamily.php`, since 33.0.0). Versioniq supports Nextcloud 32 to 34 (`appinfo/info.xml:22`).

## Goals and non-goals

Goals: one inventory, two standard formats, a copy per update, and a scrapeable metric.

Non-goals: bundled libraries (a later component list from `advisories-bundled-libraries`), signing, software outside the instance.

## Decisions

### D1. One inventory builder

`InventoryBuilder::build()` returns the server (`OCP\ServerVersion`, component type `platform`) and every app from `getInstalledApps()` with state other than `notInstalled`. Per app: id, name, version, licence (from `IAppManager::getAppInfo()`, the `licence` element), `shipped`, source (`appstore` or `github:owner/repo`), and the recorded SHA-256 when the binding has one. Both writers and the metrics family read it, so they cannot disagree.

### D2. Package URLs

There is no purl type for Nextcloud apps. A GitHub-bound app gets `pkg:github/<owner>/<repo>@<version>`, which is a registered purl type. Every other app gets `pkg:generic/nextcloud-app/<id>@<version>`. The server is `pkg:generic/nextcloud-server@<version>`.

Alternative considered: invent `pkg:nextcloud/...`. Rejected: an unregistered type breaks scanners that validate purls.

### D3. Formats

`SbomWriter` writes CycloneDX 1.5 JSON (`bomFormat`, `specVersion`, `metadata.component` for the instance, `components[]`) and SPDX 2.3 JSON (`SPDXID`, `packages[]`, a `DESCRIBES` relationship per app). Licence ids are passed through when they are SPDX ids (`EUPL-1.2`, `AGPL-3.0-or-later`); `agpl` from old `info.xml` files maps to `AGPL-3.0-or-later`; anything else goes into a licence name. JSON only: both standards accept it, and no row asks for XML.

### D4. A copy per update

After a successful real install, `InstallerService` calls `SbomArchive::writeFor(auditEntryId)` inside a try/catch, so a failure to write the SBOM never fails the install; the failure is logged. The archive keeps the SBOM in app data folder `sbom`, named after the install time and app, and keeps the newest 200 (an app config key `sbom.keep` changes it). `GET /api/audit` rows of operation `install` gain an `sbomAvailable` flag, and `GET /api/sbom/{id}` returns that copy.

### D5. Routes and command

- `GET /api/sbom?format=cyclonedx|spdx`: the current inventory, admin-only, as a download (`DataDownloadResponse`).
- `GET /api/sbom/{id}`: the copy written for that install, admin-only.
- `occ versioniq:sbom --format=cyclonedx|spdx [--output=<file>]`.
- The History tab (`src/components/HistoryPanel.vue`) gets an "Export SBOM" menu with the two formats, and each install row with a copy gets a download link.

### D6. The metrics family

`lib/OpenMetrics/AppVersionMetricFamily.php` implements `IMetricFamily`: name `versioniq_app_version`, type gauge, value 1 per app, labels `app_id`, `version`, `shipped` (`true` or `false`), `source`, and `behind` (the number of release lines behind from the availability snapshot of `inventory-pending-updates`, `unknown` when no sweep has run). `info.xml` lists it under `<openmetrics>`. On Nextcloud 32 the entry is ignored and the class is never loaded; psalm gets a stub for the 33 interface.

Alternative considered: a Versioniq-owned `/metrics` route. Rejected: the server's endpoint already carries authentication and IP rules, and a second endpoint doubles them.

## Risks and trade-offs

- [An SBOM names versions to anyone who can read it] → every route is admin-only, and the metrics family follows the server's own `/metrics` access rules.
- [200 SBOMs on a large instance] → each is tens of kilobytes; the limit is a setting.
- [`behind` depends on another change] → the label reads `unknown` until `inventory-pending-updates` lands.

## Migration

No schema change. The app data folder is created on first write. Rollback: revert and delete the `sbom` folder if wanted.
