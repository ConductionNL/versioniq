---
kind: code
---

# Proposal: advisories-bundled-libraries

## Why

An app is more than its own code. Many Nextcloud apps ship PHP libraries in their `vendor/` directory, and a known hole in one of those libraries is a hole in the instance, whether or not the app's own advisories mention it. Versioniq checks the app and the server against published advisories, and stops there. A water board's tender asks for the libraries inside a component to be checked too. The public database that covers those libraries is OSV, and Versioniq does not read it.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers two rows that need each other: the libraries are the question, OSV is where the answer is.

| Row | Rating now | What is missing |
|---|---|---|
| `adv-osv-feed` | no | Only the Nextcloud feed and source advisories are read (`lib/Service/Advisory/AdvisoryService.php:81-90`, `:166`); `grep osv` finds nothing. |
| `adv-dependencies` | no | Only apps and the server are correlated (`AdvisoryPackageMap.php:86`); bundled libraries inside an app are never inspected. |

The archived change `security-advisory-correlation` put "CVE database ingestion of arbitrary packages" out of scope, saying it correlates app-release advisories, "not a general SCA scanner". The lane decided that line bounded that change, not the product: its sibling item in the same sentence, "any automatic version change", was later built by the archived change `add-auto-update-policies`. This change keeps the spirit of the line. It reads the libraries bundled inside the installed apps, from the manifest Composer leaves in `vendor/composer/installed.json`, and matches only those against OSV. Software outside the instance stays out; row `adv-sbom-import` is decided-no.

### Demand

- `adv-dependencies`: tender, https://www.tenderned.nl/aankondigingen/overzicht/261739. Waterschap Vallei en Veluwe, zaaksysteem en DMS, requirement 171617: check the libraries bundled inside a component for vulnerabilities.
- `adv-osv-feed`: no demand row in the matrix.

### Competitors rated yes (evidence quoted from the matrix)

- `adv-osv-feed`, three rated yes. Renovate: "lib/config/options/index.ts:2459 osvVulnerabilityAlerts (experimental) uses osv.dev through @renovatebot/osv-offline (package.json:186)." Dependabot: "The GitHub Advisory Database is itself a public cross-ecosystem database published in OSV format (https://github.com/github/advisory-database) and imports PyPA, RustSec, Go and others (https://docs.github.com/en/code-security/concepts/vulnerability-reporting-and-management/github-advisory-database); Dependabot does not read osv.dev directly." OSV-Scanner: "Queries the OSV.dev API or a local OSV database copy (docs/offline-mode.md:245, docs/scan-source.md:28)."
- `adv-dependencies`, two rated yes. Dependabot: "Closed service: alerts cover transitive dependencies from the dependency graph and security updates can update them (https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-alerts, https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-security-updates); core: update_subdependencies (updater/lib/dependabot/job.rb:300)." OSV-Scanner: "Scans every package in lockfiles, transitive dependencies resolved from manifests, vendored C/C++ code and image layers (docs/supported_languages_and_lockfiles.md:79-110, docs/output.md:32-37)."

## What changes

- An admin can switch on "Check libraries bundled in apps against OSV" under Security advisory checks. It is off by default, and the setting says what leaves the server: library names and versions, nothing that identifies the instance.
- Once a day, Versioniq reads `vendor/composer/installed.json` of every enabled app, collects the PHP libraries and versions it lists (leaving out development-only packages), and asks the OSV API about all of them in one batch.
- For every library with a known advisory, it stores the advisory id, the CVE ids, the severity, a summary, the first version that fixes it and a link.
- The Advisories tab shows, per app, a "Bundled libraries" section: how many libraries were checked, the ones with advisories, and what the admin can do: look for a newer version of the app that bundles the fixed library. An app with no manifest says so: its libraries could not be checked. JavaScript built into an app's files carries no manifest and is not checked, and the page says that too.
- An app card gets a small "Library advisory" badge that links to that section.
- Library findings do not change the app's own advisory state, the compliance status or automatic security installs, because no app version is known to fix them.
- The check reports what the OSV connection met to the Integrations tab.

## Scope

In scope: the manifest reader, the OSV client, the daily job and its snapshot, the setting, the Advisories tab section, the card badge, the connection row, a fixture for e2e, tests.

Out of scope:
- The server's own `3rdparty/` libraries. Nextcloud ships and updates them with the server, and the server rows of the Nextcloud feed cover the server release; adding them is a later decision.
- JavaScript libraries built into app bundles. A release carries no manifest for them.
- Software outside the instance and SBOM import. Row `adv-sbom-import` is decided-no.
- Dismissing a library finding. `advisories-triage` covers app and server advisories; library findings are a follow-up to both changes.
- A software bill of materials per update. `inventory-export-sbom-metrics` specifies that and can read the library list this change collects.

## Impact

- New: `lib/Service/Advisory/BundledLibraryScanner.php`, `lib/Service/Advisory/OsvClient.php`, `lib/Service/Advisory/LibraryAdvisoryStore.php`, `lib/BackgroundJob/LibraryAdvisoryJob.php`, `src/components/BundledLibrariesSection.vue`.
- Changed: `lib/Service/Advisory/AdvisorySettingsStore.php` (switch), `lib/Controller/ApiController.php` (`GET /api/advisories` adds `libraries`; advisory settings), `lib/Service/Settings/InstanceSettings.php` (OSV base URL override), `lib/Service/Connection/ConnectionReportService.php` and `lib/Settings/connections.json` (row `osv`), `appinfo/info.xml` (job), `src/components/AdvisoriesPanel.vue`, `src/App.vue` (switch, badge), `tests/e2e/fixtures/forge/server.mjs` and `build-artifacts.sh` (an OSV double and a fixture app with a vendored library), `l10n/en.json`, `l10n/nl.json`.
- Capability spec `security-advisory-correlation`: one ADDED requirement.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the library findings are advisory data it can read.

## Rollback

Revert the change. The switch (`advisory.libraries_enabled`), the snapshot (`advisory.libraries`, `advisory.libraries.checkedAt`) and the base URL override (`advisory.osv_api_base`) are app config keys nothing else reads, and a revert that removes the job class also adds it to the `RETIRED_JOB_CLASSES` list of `lib/Repair/RemoveRetiredCronJobs.php` (`:79`), so its `oc_jobs` row goes too.
