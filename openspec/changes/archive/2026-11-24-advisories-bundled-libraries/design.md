# Design: advisories-bundled-libraries

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AdvisoryService` takes the source registry, the binding store, the app manager, the Nextcloud feed, the branch range, the server version and a logger (`lib/Service/Advisory/AdvisoryService.php:81-90`). It correlates each enabled app (`:174-213`) and the server (`:218-225`) with advisories about the app or the server only.
- `AdvisoryPackageMap::resolve()` (`lib/Service/Advisory/AdvisoryPackageMap.php:86`) maps an advisory's package to an app id or the server; a library name resolves to nothing and is dropped by design (`:43-46`).
- `AdvisoryResultStore` (`lib/Service/Advisory/AdvisoryResultStore.php`) is the pattern for a stored snapshot with `checkedAt`: JSON in app config, the previous snapshot kept when encoding fails (`:60-76`), `checkedAt: null` for never checked (`:86-119`).
- `AdvisoryRefreshJob` sweeps with a 600 s budget (`lib/BackgroundJob/AdvisoryRefreshJob.php:47`); background jobs are listed in `appinfo/info.xml` `<background-jobs>`.
- `IAppManager::getAppPath()` is how the installers find an app directory (`lib/Service/InstallerService.php:545`, `lib/Service/SelectedReleaseInstallerService.php:317`).
- `AdvisorySeverity::normalize()` maps `moderate` to `medium` (`lib/Service/Advisory/AdvisorySeverity.php:38`), the word GHSA-sourced records use.
- `ConnectionReportService` reports what an outside call met, one key per connection (`lib/Service/Connection/ConnectionReportService.php:51-57`, `:192`).
- The forge fixture serves the e2e instance and has a control surface for releases and advisories (`tests/e2e/fixtures/forge/server.mjs:100-199`); `build-artifacts.sh` builds the fixture app tarballs.

## Goals and non-goals

Goals: the PHP libraries bundled in installed apps are matched daily against OSV; findings are shown per app with a fix version and a clear next step; nothing leaves the server unless an admin switched it on.

Non-goals: the server's `3rdparty/`, JavaScript bundles, software outside the instance, dismissing library findings, SBOMs (see `inventory-export-sbom-metrics`).

## Decisions

### D1. The manifest is Composer's `installed.json`

`BundledLibraryScanner::scan(string $appId): array{manifest: ?string, libraries: list<array{name: string, version: string}>, skipped: int}` reads `{appPath}/vendor/composer/installed.json`. It accepts both layouts Composer writes: an object with `packages` (Composer 2) and a plain list (Composer 1). It leaves out every name in `dev-package-names` when that key is present, and every version that starts with `dev-` (a branch, which OSV cannot match); those count as `skipped`. A leading `v` is stripped. When the file is absent, `manifest` is null and the app is shown as "no library manifest, not checked".

The file is the proof that the libraries are bundled: Composer writes it into the `vendor/` directory it installs. A `composer.lock` without a `vendor/` directory says what a developer would install, not what ships, so it is not read.

Alternative considered: parse `composer.lock` too. Rejected for that reason. Apps that prefix their libraries into their own namespace (for example into `lib/Vendor`) often ship no `installed.json`; the page names them as not checked rather than guessing.

### D2. OSV, in one batch

`OsvClient` uses `IClientService` with the SSRF guard the forge client uses (`allow_local_remote_servers`) and a 30 s timeout per call.

- `POST {base}/v1/querybatch` with one query per distinct `(name, version)` across all apps, ecosystem `Packagist`, at most 1000 queries per request. The answer lists, per query in order, the ids of matching vulnerabilities; a `next_page_token` on a result is followed.
- `GET {base}/v1/vulns/{id}` once per distinct id, for `summary`, `aliases` (CVE ids), `published`, `database_specific.severity`, `references`, and `affected[].ranges[].events` from which the first `fixed` version for that package is taken.
- `{base}` defaults to `https://api.osv.dev` and can be overridden with `advisory.osv_api_base` (https only) from the Settings tab, for a mirror or the e2e fixture.

A run stops at 120 s or 500 detail calls, whichever comes first, and stores what it has, marking the rest "not checked this run".

Alternative considered: the OSV offline database. Rejected for now: it is a multi-gigabyte download per ecosystem, which an admin-only utility should not pull into app data. The base URL override lets an instance point at its own OSV mirror.

### D3. A snapshot beside the advisory snapshot

`LibraryAdvisoryStore` keeps `advisory.libraries` (JSON) and `advisory.libraries.checkedAt` on the `AdvisoryResultStore` pattern:

```json
{"mail": {"manifest": "vendor/composer/installed.json", "checked": 41, "skipped": 1, "error": null,
          "findings": [{"package": "vendor/library", "version": "2.4.3", "id": "GHSA-xxxx-xxxx-xxxx",
                        "cveIds": ["CVE-0000-00000"], "severity": "medium",
                        "summary": "…", "fixedIn": "2.4.5", "url": "https://osv.dev/vulnerability/GHSA-xxxx-xxxx-xxxx"}]}}
```

The example shows the shape; its ids and versions are placeholders. Severity comes from `database_specific.severity` through `AdvisorySeverity::normalize()`, else `unknown`; a CVSS vector alone is not turned into a level.

`LibraryAdvisoryJob` is a daily `TimedJob` that does nothing while `advisory.libraries_enabled` is false. It is separate from `AdvisoryRefreshJob` so a slow OSV call can never cut the app and server sweep short, and because bundled libraries only change when an app is updated.

### D4. Findings stay apart from the app's advisory state

The app's `state`, the compliance status (`advisories-per-app-detail`) and security installs (`autoupdate-security-first`) read only the app and server advisories. A library finding has no app version that is known to fix it, so it cannot drive an install or a "safe version". It shows as its own section and badge.

### D5. The page

`GET /api/advisories` adds `libraries` and `librariesCheckedAt`. `BundledLibrariesSection.vue` renders inside each app block of the Advisories tab: "{n} PHP libraries checked", each finding with package, bundled version, advisory id, CVE ids, severity, "Fixed in {version}" and the link, and the sentence "Look for a newer version of this app that bundles {package} {version} or later, or ask its maintainer." Apps without a manifest read "No library manifest found, so its libraries were not checked." A line under the section says that JavaScript built into the app is not checked. An app card with findings gets a "Library advisory" badge linking to the section. The switch under Security advisory checks reads "Check libraries bundled in apps against OSV (sends library names and versions to osv.dev)".

### D6. Connection row

`connections.json` gains a reportedOnly row `osv` ("OSV vulnerability database"), and each run reports through `ConnectionReportService` how many libraries it asked about and whether OSV answered.

## Risks and trade-offs

- [A vendored library was patched by the app maintainer without a version change] → the finding shows the bundled version and the advisory; it is information for the admin, never an automatic action.
- [OSV is unreachable] → the snapshot keeps the previous result with its time, the page shows the age, and the connection row says what failed.
- [Many apps bundle the same library] → queries are deduplicated per `(name, version)`, so the batch stays small.
- [An admin does not want library names sent out] → the check is off until switched on, and the switch says what is sent.

## Migration

No schema change. The job is registered in `info.xml` and does nothing until the switch is on. Rollback: see the proposal.
