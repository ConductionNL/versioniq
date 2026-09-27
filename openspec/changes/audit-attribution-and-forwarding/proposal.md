---
kind: code
---

# Proposal: audit-attribution-and-forwarding

## Why

Versioniq's history answers "what changed" and leaves out "who, through which door, and who changed the rules". An update the nightly job made and an update an admin ran with `occ` both read actor `system`. Turning automatic updates on, moving the window, or changing an app's policy leaves no row at all. And the history stays inside Versioniq's own table, where neither the security team's log collector nor the Nextcloud activity stream can see it.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers four rows that all touch `AuditLogger`.

| Row | Rating now | What is missing |
|---|---|---|
| `aud-auto-attributed` | partial, built | The missing half: automatic installs and `occ` installs are both recorded as actor `system` (`lib/Service/InstallerService.php:1037-1039`, `lib/Service/SelectedReleaseInstallerService.php:444`), so they cannot be told apart. |
| `aud-log-policy` | no | The kill switch, the window, the advisory settings and every per-app policy change write no audit row (`lib/Controller/ApiController.php:752`, `795`, `822-861`, `912-953`). The row note says no settings are audited; that is too broad: the Settings tab fields are audited as operation `settings` (`lib/Controller/SettingsController.php:120-134`). |
| `aud-siem` | no | Audit rows go only to the app's own table (`lib/Service/Audit/AuditLogger.php:60-110`). |
| `aud-activity-stream` | no | Version changes are not published to the Nextcloud activity stream. |

### Demand

- `aud-log-policy`: competitor changelog, https://github.blog/changelog/2026-02-10-track-additional-dependabot-configuration-changes-in-audit-logs (audit events for turning Dependabot security updates on or off).
- The other three have no demand row; each is rated yes by two or three competitors.

### Competitors rated yes (evidence quoted from the matrix)

- `aud-auto-attributed`: Renovate, "Automerged updates are merged by the bot account (automerge lib/config/options/index.ts:2376)". Dependabot, "updates are authored by the dependabot[bot] account". Easy Updates Manager, "Each entry records action automatic or manual (includes/MPSUM_Logs.php:122, 300, 408) and the Logs tab filters on it".
- `aud-log-policy`: Renovate, "The update policy ... lives in the repository's Renovate config file, so every change is a commit with author and time". Dependabot, "since 2026-02-10 organisation and enterprise audit logs record who enabled or disabled Dependabot security updates".
- `aud-siem`: Nextcloud, "log_type_audit supports file, syslog, systemd and errorlog with a separate syslog tag (apps/admin_audit/lib/AuditLogger.php:24-34)". Easy Updates Manager, "send log entries to the PHP error log, e-mail, syslog or Slack via webhook (https://easyupdatesmanager.com/knowledge-base/log-clearance-and-to-external-channels-premium/)".
- `aud-activity-stream`: Renovate, "PRs, merges and dashboard updates appear in the forge's own activity feed". Dependabot, "pull requests and alerts appear in the repository activity and notifications inbox (https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/manage-your-dependency-security/configure-dependabot-notifications)".

The archived `2026-07-23-add-version-audit-trail` proposal put "Nextcloud Activity / Notification integration" out of scope. Notifications were built later anyway, so that line bounded the change, not the product. Hydra ADR-001 names the activity service for history feeds.

## What changes

- Every audit row records where it came from: the web page, the command line, the nightly job, or the drift listener. The History tab shows it next to the actor and can filter on it.
- Changing the kill switch, the window, the advisory settings or an app's policy writes an audit row with the value before and after. The card shows who set the policy and when.
- Every audit row is also sent to Nextcloud's audit log as a critical action, so the `admin_audit` app writes it to the file, syslog or journal the instance already ships to its log collector.
- Installs, rollbacks, pins and policy changes appear in the activity stream of every admin, under a Versioniq activity setting admins can switch off.

## Scope

In scope: the origin column and its migration, the four new audit operations, the audit event, the activity provider and setting, the History tab filter, tests.

Out of scope:
- Token lifecycle audit (row `aud-log-tokens`, deferred).
- Trusted-source list audit (row `aud-log-source`, deferred).
- A Versioniq-owned syslog or webhook sender. The platform's audit log is the channel; `releases-new-release-alerts` specifies webhooks for alerts.

## Impact

- New: `lib/Migration/Version1005Date20260927120000.php` (origin column), `lib/Activity/Provider.php`, `lib/Activity/Setting.php`.
- Changed: `lib/Service/Audit/AuditLogger.php` (origin, the audit event, the activity publish), `lib/Db/AuditEntry.php`, `lib/Service/InstallerService.php` and both installers (pass the origin), `lib/Command/InstallVersion.php`, `lib/BackgroundJob/AutoUpdateJob.php`, `lib/Service/Pin/PinDriftHandler.php`, `lib/Controller/ApiController.php` (four writes audited, origin filter on `GET /api/audit`), `appinfo/info.xml` (activity provider and setting), `src/components/HistoryPanel.vue`, `src/components/PolicySelector.vue`, `l10n`.
- ADDED requirements in `audit-trail`.

### MCP coverage

No MCP surface in this change: it records actions, it adds none.

## Rollback

Revert the change. The migration only adds a nullable column, which older code ignores; activity entries already published stay in the activity app.
