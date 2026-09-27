---
kind: code
---

# Proposal: advisories-per-app-detail

## Why

The Advisories tab lists every stored advisory per app, but an admin who is asked "which CVEs were reported for this product lately, and are we patched?" still cannot answer from it. The list shows GHSA ids without the CVE ids a tender or an auditor asks for, without dates, in no particular order. When no update fixes an affected version, the page shows no safe version and says nothing about why. And there is no single answer to "are the latest security patches installed?": each card has its own badge, and the freshness line says when the check ran.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers three rows that all rated partial, built. It specifies the missing half of each.

| Row | Rating now | What is built | What is missing |
|---|---|---|---|
| `adv-cve-history` | partial, built | The Advisories tab lists each app's stored advisories with id, severity and summary (`src/components/AdvisoriesPanel.vue:71-98`); the card badge's tooltip shows the first summary (`src/App.vue:2233-2237`). | CVE ids, publication dates and newest-first order per app, and a way from the card to that list. The row's note says the per-app list is not in the UI; that part of the note predates the Advisories tab. |
| `adv-unfixable-reason` | partial, built | When no version resolves an advisory, `recommendedVersion` stays null and no safe version is shown (`lib/Service/Advisory/AdvisoryService.php:304-306`, `:459`). | The reason: no fix published, the fix is not offered by the bound source, or the fix needs a newer Nextcloud. |
| `aud-patch-compliance` | partial, built | Each card says whether its version is affected, and the page says when that was checked (`src/App.vue:2039-2043`). The server's own row is shown in the Nextcloud server block of the Advisories tab (`src/components/AdvisoriesPanel.vue:32-64`); the row's note says it is shown nowhere, which predates that block. | One overall patch compliance status for the server and all apps, and a way for a script to read it. |

### Demand

- `adv-cve-history`: tender, https://canadabuys.canada.ca/en/tender-opportunities/BPM025740/33472. Shared Services Canada, Cisco maintenance, requirement 170202: the most recent CVEs reported for each installed product.
- `adv-unfixable-reason`: feature request, https://github.com/google/osv-scanner/issues/925. Explain why a vulnerability cannot be fixed by an update.
- `aud-patch-compliance`: tender, https://www.tenderned.nl/aankondigingen/overzicht/196310. Gemeente Zeist, zaaksysteem, requirement 31492: show whether the latest security patches are installed.

### Competitors rated yes (evidence quoted from the matrix)

- `adv-cve-history`: no competitor is rated yes. Renovate and Dependabot are partial; Renovate's "dependencyDashboardOSVVulnerabilitySummary=all lists the CVEs known for each dependency on the dashboard (dependency-dashboard.ts:780); not a recency-ordered history."
- `adv-unfixable-reason`, Dependabot rated yes: "Core: when no security update is possible the job records security_update_not_possible with the latest allowed version, the lowest fixed version and the conflicting dependencies that block it, or all_versions_ignored when ignores block it (updater/lib/dependabot/updater/security_update_helpers.rb:47, :73, :84-106); closed service: each case is explained in https://docs.github.com/en/code-security/reference/supply-chain-security/troubleshoot-dependabot/dependabot-errors."
- `aud-patch-compliance`, OSV-Scanner rated yes: "Exit code 1 when vulnerabilities remain and 0 when none (docs/output.md:790-799) gates pull requests and releases as a pass or fail status (docs/github-action.md:27-60, :113-156)."

## What changes

- The sweep keeps, per advisory, its CVE ids, its publication date and a link, next to the GHSA id. For the Nextcloud feed these come from the same GHSA record it already reads.
- Per app and for the server, the Advisories tab lists the advisories newest first, each with its CVE ids, GHSA id, date, severity and whether it affects the installed version. It shows the five most recent and a "Show all" for the rest.
- The card's advisory badge links to that app's list on the Advisories tab.
- When an installed version is affected and no installable fix exists, the page names the reason: no fixed version is published yet, the bound source does not offer the fixed version, or the fixed version needs a newer Nextcloud. When a fix exists but the app is pinned below it, the page says the pin holds it back.
- One patch compliance status for the instance: compliant, not compliant (with the number of affected apps per highest severity), or unknown (never checked, the check is older than twice its interval, or some apps could not be checked). It shows at the top of the Apps tab and the Advisories tab.
- `occ versioniq:advisories` prints the per-app list and the status, as a table or JSON. With `--check` it exits 0 when compliant, 1 when not, 2 when unknown, so a monitoring script can alert on it.

## Scope

In scope: the extra fields on stored advisories, the ordered list and the card link, the reason for a missing fix, the compliance status, the command, tests.

Out of scope:
- Dismissing an advisory. `advisories-triage` specifies it; the status counts only advisories that are not dismissed once it lands.
- Deadlines and how fast advisories were fixed. `audit-security-reports` specifies those.
- NCSC-NL advisories. `advisories-ncsc-feed` specifies them and reads the CVE ids stored here to merge duplicates.
- Advisories about bundled libraries. `advisories-bundled-libraries` specifies them; they do not change this status.

## Impact

- New: `lib/Service/Advisory/ComplianceStatus.php`, `lib/Service/Advisory/FixStatus.php`, `lib/Command/ListAdvisories.php`.
- Changed: `lib/Service/Advisory/NextcloudAdvisoryFeed.php` (keep `cve_id`, `identifiers`, `published_at`, `html_url`), `lib/Service/Source/ForgeReleaseSource.php` and `lib/Service/Source/AppStoreSource.php` (the same fields when present), `lib/Service/Advisory/AdvisoryService.php` (`history`, `fixStatus`), `lib/Controller/ApiController.php` (`GET /api/advisories` adds `compliance`), `appinfo/info.xml` (command), `src/components/AdvisoriesPanel.vue`, `src/utils/advisories.ts`, `src/App.vue` (badge link, status line), `l10n/en.json`, `l10n/nl.json`.
- Capability specs: `security-advisory-correlation` three ADDED requirements; `cli-commands` one ADDED requirement.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the ordered list, the fix reason and the status are what an assistant needs to explain results.

## Rollback

Revert the change. The new fields sit inside the stored advisory snapshot (`advisory.results`); older code ignores fields it does not read, and the next sweep after a revert writes the old shape. The command disappears with the code. No other state is added.
