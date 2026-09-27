---
kind: code
---

# Proposal: advisories-triage

## Why

Every advisory that affects an installed version stays on the page, and on the app card, until the app is updated. An admin who checked an advisory and found it does not apply here, because the affected feature is switched off or the risk is accepted, has no way to record that. The badge keeps shouting, the weekly digest keeps counting it, and the next admin checks it again. And advisories go to every admin at once: nobody can hand one to the colleague who will fix it.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers two rows that both put a human decision on one advisory.

| Row | Rating now | What is missing |
|---|---|---|
| `adv-dismiss` | no | No way to dismiss or ignore an advisory with a reason. `AdvisoryNotifier` stores which advisories it notified about (`AdvisoryNotifier.php:36`), not decisions. |
| `adv-assign-owner` | no | Advisories go to every admin; they cannot be assigned. The stored snapshot has no assignee (`AdvisoryResultStore.php`). |

### Demand

- `adv-assign-owner`: changelog, https://github.blog/changelog/2026-04-07-dependabot-alerts-are-now-assignable-to-ai-agents-for-remediation/. Dependabot alerts became assignable, to people and to AI agents.
- `adv-dismiss`: no demand row in the matrix.

### Competitors rated yes (evidence quoted from the matrix)

- `adv-dismiss`, two rated yes. Dependabot: "Closed service: dismiss with a reason from a list plus a comment kept on the alert timeline, singly or in bulk; auto-triage rules dismiss by criteria (https://docs.github.com/en/code-security/how-tos/manage-security-alerts/manage-dependabot-alerts/view-dependabot-alerts, https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-auto-triage-rules)." OSV-Scanner: "[[IgnoredVulns]] with id, reason and optional ignoreUntil in osv-scanner.toml (docs/configuration.md:25-43, internal/config/config.go:26-30); the reason is logged when a finding is filtered (pkg/osvscanner/filter.go:59)."
- `adv-assign-owner`, two rated yes. Renovate: "vulnerabilityAlerts is a mergeable config object (lib/config/options/index.ts:2428) that takes assignees, reviewers and labels for security PRs, and assigneesFromCodeOwners (lib/config/options/index.ts:2731) assigns them from CODEOWNERS." Dependabot: "Closed service: alerts take assignees (users, teams or AI agents) from the alert page (https://docs.github.com/en/code-security/how-tos/manage-security-alerts/manage-dependabot-alerts/view-dependabot-alerts), with repository_vulnerability_alert.assign audit events (https://docs.github.com/en/organizations/keeping-your-organization-secure/managing-security-settings-for-your-organization/audit-log-events-for-your-organization)."

## What changes

- On the Advisories tab an admin dismisses one advisory for one app, with a reason from a fixed list, an optional comment and an optional end date. The reasons: the affected feature is not used here, the advisory is inaccurate for this setup, the risk is tolerable, the risk is mitigated another way, a fix is planned (which needs an end date).
- A dismissed advisory stops counting: it leaves the badge and its severity, the notifications, the weekly digest, and security-first installs. It stays visible in a Dismissed list with the reason, who, when and until when, and can be reopened.
- A dismissal ends by itself on its end date, and when the app's installed version changes. If the advisory still affects the new version, it is back and needs a new decision.
- An admin assigns an advisory to an admin, or to a group, whose admin members then own it. The assignee gets a notification. The tab can show only the advisories assigned to me.
- Every dismissal, reopen and assignment writes an audit entry.

## Scope

In scope: the triage store, three routes, the triage fields on `GET /api/advisories`, the notifier and digest changes, the Advisories tab controls and the dismiss dialog, audit entries, tests.

Out of scope:
- Rules that dismiss advisories by criteria (auto-triage). A fixed reason per advisory is enough to record a decision; rules can follow.
- Assigning to someone who is not an admin. Versioniq is admin-only, so an assignee must be able to open it.
- Advisories about bundled libraries. `advisories-bundled-libraries` specifies those; they get triage when that change lands.
- Deadlines per severity. `autoupdate-security-first` records them; `audit-security-reports` reports on them and counts dismissals separately.

## Impact

- New: `lib/Service/Advisory/AdvisoryTriage.php`, `lib/Service/Advisory/AdvisoryTriageStore.php`, `lib/Controller/AdvisoryTriageController.php`, `src/dialogs/DismissAdvisoryDialog.vue`.
- Changed: `lib/Controller/ApiController.php` (`GET /api/advisories` adds `triage`), `lib/Service/Advisory/AdvisoryNotifier.php` and `AdvisoryDigestNotifier.php` (skip dismissed), `lib/Service/Audit/AuditLogger.php` (three operations), `lib/Notification/Notifier.php` (`advisory_assigned`), `src/components/AdvisoriesPanel.vue`, `src/utils/advisories.ts`, `src/App.vue` (badge ignores dismissed), `l10n/en.json`, `l10n/nl.json`.
- Capability spec `security-advisory-correlation`: two ADDED requirements.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; triage decisions are advisory data it can read, and dismissing stays with the admin on the page.

## Rollback

Revert the change. Triage lives in one app config key, `advisory.triage`. Without the code every advisory counts again, which is the state before this change; nothing is lost from the snapshot, because dismissals are applied when read, not written into it. Audit entries written in the meantime stay in the trail.
