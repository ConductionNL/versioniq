---
kind: code
---

# Proposal: advisories-ncsc-feed

## Why

Dutch public bodies follow the advisories of the Nationaal Cyber Security Centrum (NCSC-NL). A municipality that runs Nextcloud checks NCSC-NL for its products, and tenders ask the tool that manages versions to do that check. Versioniq reads only Nextcloud's own advisory feed and the forge advisories of external sources. An NCSC-NL advisory about Nextcloud never reaches the page.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `adv-ncsc` | no | NCSC advisories are not read. `grep ncsc` in `lib` and `src` finds nothing. |

NCSC-NL publishes its advisories as CSAF 2.0 documents. Its provider metadata (`https://advisories.ncsc.nl/.well-known/csaf/provider-metadata.json`, read on 2026-09-27) names it a `csaf_trusted_provider` with one directory distribution, `https://advisories.ncsc.nl/csaf/v2/`, whose `changes.csv` lists every document with its last change, newest first. Each document names products by vendor, product name and version range, lists CVE ids, and carries NCSC-NL's own likelihood (`Kans`) and damage (`Schade`) ratings. That is enough to match an advisory to the installed server and apps without any other source.

### Demand

- `adv-ncsc`: tender, https://www.tenderned.nl/aankondigingen/overzicht/406416. Gemeente Zuidplas, zaaksysteem, requirement 183111: NCSC advisories are matched to the installed software.

### Competitors rated yes (evidence quoted from the matrix)

No competitor is rated yes. Nextcloud, Dependabot, OSV-Scanner and Easy Updates Manager are rated no; Renovate is rated unknown.

## What changes

- An admin can switch on "Also check NCSC-NL advisories" under Security advisory checks. It is off by default, because it is a new outside connection.
- The advisory sweep reads NCSC-NL's `changes.csv`, fetches only documents it has not read or that changed, and keeps those that name a Nextcloud product.
- Each such advisory is matched to the server or to an installed app, with the same name matching the Nextcloud feed uses. Clients (desktop, mobile) are dropped.
- When the document states a version range, the installed version is checked against it. When it does not (document NCSC-2026-0389, read on 2026-09-27, gives `vers:unknown/*`), the advisory is listed as "NCSC-NL does not state which versions are affected" and does not mark the version affected.
- An NCSC-NL advisory that shares a CVE with a Nextcloud advisory for the same app shows as one entry with both ids, not twice.
- On the Advisories tab each NCSC-NL advisory shows its id, Dutch title, CVE ids, severity, NCSC-NL's likelihood and damage ratings, publication date and a link.
- The sweep reports what the NCSC-NL read met to the Integrations tab, like the other connections.

## Scope

In scope: the NCSC-NL reader (index, incremental read, product match, version range check), the merge into the sweep, the setting, the display, the connection row, a fixture for e2e, tests.

Out of scope:
- Software outside the instance. Only the Nextcloud server and installed apps are matched; row `adv-sbom-import` is decided-no.
- NCSC-NL advisories about bundled libraries. `advisories-bundled-libraries` reads OSV for those.
- Other national CERTs. The reader is written for CSAF directory distributions, so another provider is a later configuration, not a rewrite, but none is added here.
- CVE ids and dates on Nextcloud feed records. `advisories-per-app-detail` adds them; the CVE merge above uses them once present.

## Impact

- New: `lib/Service/Advisory/NcscAdvisoryFeed.php`, `lib/Service/Advisory/CsafProductMatcher.php`, `lib/Service/Advisory/VersRange.php`, `tests/e2e/fixtures/forge/ncsc/` (CSAF fixture served by the fixture server).
- Changed: `lib/Service/Advisory/AdvisoryService.php` (merge the NCSC-NL records), `lib/Service/Advisory/AdvisorySettingsStore.php` (switch), `lib/Controller/ApiController.php` (`GET` and `PUT /api/advisory/settings`), `lib/Service/Connection/ConnectionReportService.php` and `lib/Settings/connections.json` (row `ncsc`), `lib/Service/Settings/InstanceSettings.php` (base URL override), `src/App.vue` (switch), `src/components/AdvisoriesPanel.vue`, `src/utils/advisories.ts`, `tests/e2e/fixtures/forge/server.mjs`, `l10n/en.json`, `l10n/nl.json`.
- Capability spec `security-advisory-correlation`: one ADDED requirement.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; NCSC-NL advisories become part of the advisory data it reads.

## Rollback

Revert the change. The switch (`advisory.ncsc_enabled`), cursor (`advisory.ncsc.cursor`), index (`advisory.ncsc.index`) and base URL override (`advisory.ncsc_feed_base`) are app config keys nothing else reads. The next sweep after a revert stores a snapshot without NCSC-NL records.
