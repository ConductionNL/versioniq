---
kind: code
---

# Proposal: audit-security-reports

## Why

A managed service provider owes its client a monthly security report: which security updates went in, per risk level how many advisories were fixed within the agreed time, what is still open, and how updates went. Versioniq holds most of the facts. The History tab lists every install with who, when and from which to which version. But the admin still has to build the report by hand. The history cannot be cut to a month, does not say which installs fixed an advisory, cannot be exported, and does not include server updates. And the advisory snapshot is overwritten by every check, so nobody can say when an advisory first affected the instance or how long it took to fix.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers two rows.

| Row | Rating now | What is missing |
|---|---|---|
| `aud-security-update-report` | partial, built | The built half: the History tab lists every install, manual or automatic, with time and versions, newest first, filterable by app (`lib/Controller/ApiController.php:1258`, `src/components/HistoryPanel.vue:65-98`). The missing half: a period filter, a mark on installs that fixed an advisory, an export, and server updates. |
| `aud-remediation-kpi` | no | Advisory results are one snapshot that each check overwrites (`lib/Service/Advisory/AdvisoryResultStore.php:60`), so there is no history to measure time to fix or trends, and there are no deadlines per severity. |

### Demand

- `aud-security-update-report`: tender, https://www.tenderned.nl/aankondigingen/overzicht/418601. GGD GHOR Nederland, Basis ICT/IV Voorzieningen MSP en MSSP, requirement 205044 (also 91441 in tender 411356): an overview of executed security updates and patches on systems, software and infrastructure.
- `aud-remediation-kpi`: tender, https://www.tenderned.nl/aankondigingen/overzicht/408509. ICT Managed Workplace, requirement 104492: a monthly security status report with vulnerabilities per risk level, remediation percentages within deadlines, open exceptions, patch compliance per category and rollout success and failure.

### Competitors rated yes (evidence quoted from the matrix)

- `aud-security-update-report`, Dependabot rated yes: "Closed service: alert metrics count alerts fixed by Dependabot, dismissed and auto-dismissed, with trends over time and across the organisation (https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-alert-metrics), and the alerts API lists fixed alerts with fixed_at for any period (https://docs.github.com/en/rest/dependabot/alerts). The server maps to the repository itself, so there is no separate server part."
- `aud-remediation-kpi`: no competitor is rated yes. Dependabot is partial: "alert metrics show fixed, dismissed and auto-dismissed alerts by severity with trends over time ... the page defines no deadlines, so the share resolved within a deadline is not shown."

## What changes

- After every advisory check, Versioniq writes to the history when an advisory starts to affect an installed version (`advisory_open`) and when it stops (`advisory_resolved`), with how: by an update, by a dismissal, or because the advisory was withdrawn. Each entry names the advisory and its severity.
- The same check notices when the Nextcloud server version changed and writes a `server_update` entry.
- The History tab gets a period (from and to), a "Security updates only" filter and an export to CSV and JSON. A security update is an install or server update after which an advisory was resolved by that update. Each such row names the advisories it fixed.
- A security report for a chosen month or period: the security updates applied, install outcomes (installed, failed), per severity the advisories opened, fixed, fixed within the deadline, open past the deadline and open exceptions (dismissals), the median days to fix, and a month-by-month trend over the last twelve months. It exports to CSV.
- Deadlines come from the patch policy (`autoupdate-security-first`). Without deadlines, the report shows the counts and says to set deadlines for the share within deadline.

## Scope

In scope: the advisory exposure tracker, the `server_update` detection, one new audit column, the period and security filters, the security report endpoint and page, CSV and JSON export, tests.

Out of scope:
- Deadlines per severity themselves. `autoupdate-security-first` records them in the patch policy.
- Dismissals. `advisories-triage` specifies them; this change counts them as open exceptions and as a way an advisory was resolved.
- App version changes made outside Versioniq. `audit-external-changes` records those; once it does, they count as updates in this report too.
- Telling automatic from command-line installs. `audit-attribution-and-forwarding` specifies that; until it lands the report counts installs by outcome, and splits them by origin once the history records it.
- Sending the report somewhere on a schedule. An export is enough for a monthly report; a scheduled mail can follow.

## Impact

- New: `lib/Service/Advisory/AdvisoryExposureTracker.php`, `lib/Service/Report/SecurityReportService.php`, `lib/Controller/ReportController.php`, `lib/Migration/` (one step adding a nullable `advisories` column to `app_versions_audit`), `src/components/SecurityReportPanel.vue`.
- Changed: `lib/BackgroundJob/AdvisoryRefreshJob.php` (run the tracker after saving), `lib/Db/AuditEntry.php` and `lib/Db/AuditEntryMapper.php` (the column, a period query), `lib/Service/Audit/AuditLogger.php` (three operations, the column), `lib/Controller/ApiController.php` (`GET /api/audit` gains `from`, `to`, `securityOnly`), `src/components/HistoryPanel.vue`, `l10n/en.json`, `l10n/nl.json`.
- Capability specs: `audit-trail` two ADDED requirements; `security-advisory-correlation` one ADDED requirement.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the report endpoint is read-only data it can serve.

## Rollback

Revert the change and roll back the migration only if the column must go; a nullable column that nothing reads is harmless. The working set of open exposures lives in app config key `advisory.exposures` and the last seen server version in `advisory.last_server_version`; both can be deleted with `occ config:app:delete`. Audit entries written in the meantime stay, as audit entries always do.
