---
kind: code
---

# Proposal: audit-external-changes

## Why

Versioniq's History tab records every install Versioniq made. It does not record the ones it did not make. When an admin updates an app on Nextcloud's own Apps page, runs `occ app:update`, or upgrades the server, the version changes and the history says nothing, unless the app happened to be pinned. An admin who asks "what changed last night" gets half the answer.

A server upgrade also disables apps that do not declare support for the new major. Nextcloud writes a log line and moves on; nobody is told, and the app stays off until someone notices it missing.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers two rows that share one record of what each app last ran.

| Row | Rating now | What is missing |
|---|---|---|
| `aud-external-changes` | partial, built | The missing half: a change made outside Versioniq is recorded only for a pinned app, as a `pin_drift` row. Changes to unpinned apps are not recorded, and the trigger (occ, the Apps page, another tool) is never captured. |
| `saf-reactivate-after-update` | partial, built | The missing half: an install through Versioniq keeps the app's enabled state, but an app disabled by another path, such as a server upgrade, is neither reported nor re-enabled. |

### Demand

- `aud-external-changes`: changelog, https://wordpress.org/plugins/stops-core-theme-and-plugin-updates/#developers. Easy Updates Manager 9.0.20 (2025-12-08) processes external update logs early, so updates run through WP-CLI are still logged after a fatal error, and the log keeps a stack trace of the trigger.
- `saf-reactivate-after-update`: changelog, the same page. The matrix records no note beyond it.

### Competitors rated yes (evidence quoted from the matrix)

- `aud-external-changes`, Easy Updates Manager rated yes: "The log hooks WordPress's upgrader itself (includes/MPSUM_Logs.php:95, :271, :388), so updates run from the core screens, background updates, WP-CLI or another plugin are logged with user, from and to version and a stack trace of the trigger (includes/MPSUM_Logs.php:109-122, :513) ... Files replaced outside the upgrader, such as by FTP, are not seen."
- `saf-reactivate-after-update`, Easy Updates Manager rated yes: "Premium, docs: auto-update protection detects plugins deactivated by an update, re-activates them and reports by e-mail (https://easyupdatesmanager.com/knowledge-base/auto-update-protection-premium/); changelog 9.0.18 reactivates in dependency order."

## What changes

- Versioniq keeps what it last saw of every app: its version and whether it was enabled. Its own installs update that record, so they are never counted twice.
- Every version change Nextcloud reports with its app update event is written to the history as an `external_update` row: who, from which version to which, and the trigger. The trigger is the `occ` command line on the command line, or the request path in a web request.
- A job compares every app with the record every hour. A change no event reported, such as files replaced by hand or a restored backup, is written as an `external_update` row with the trigger "found by the hourly check".
- When an app that was enabled is found disabled right after its version or the server's version changed, Versioniq notifies admins. If the installed version runs on this server, the notification and the card offer Enable. An admin can let Versioniq re-enable such apps on its own. An app that does not run on this server is named as such, with the newest version that does when one is known.
- An app an admin disabled on purpose, without a version change, is recorded and never re-enabled.

## Scope

In scope: the record of last-seen state, the listener for all apps, the hourly check, the trigger text, the disabled-by-update detection, the notification, the automatic re-enable setting, tests.

Out of scope:
- Blocking an update made outside Versioniq. Nextcloud offers no hook that can veto one; the archived `add-version-pinning` proposal records that as a non-goal.
- Forcing an incompatible app back on. `install-force-incompatible` specifies "Enable anyway".
- Sending these rows elsewhere. `audit-attribution-and-forwarding` specifies forwarding and the attribution of automatic changes.

## Impact

- New: `lib/Service/Audit/SeenStateStore.php`, `lib/Service/Audit/ExternalChangeRecorder.php`, `lib/Service/Audit/TriggerDescriber.php`, `lib/Service/Reactivation/ReactivationService.php`, `lib/Listener/AppDisabledListener.php`, `lib/BackgroundJob/ExternalChangeReconcileJob.php`.
- Changed: `lib/Listener/AppUpdatedListener.php` (every app, not only pinned ones), `lib/AppInfo/Application.php` (the disable listener), `lib/Service/Installer/InstallFinalizer.php` (updates the record after a Versioniq install), `lib/Service/Audit/AuditLogger.php` (operations `external_update`, `external_disable`, `reactivate`), `lib/Notification/Notifier.php` (subject `app_left_disabled`), `lib/Service/Settings/InstanceSettings.php` (the automatic re-enable switch), `appinfo/info.xml` (job), `src/App.vue` (card notice), `src/components/HistoryPanel.vue` (labels and trigger), `src/components/InstanceSettingsPanel.vue`, `l10n/en` and `l10n/nl`.
- ADDED requirements in `audit-trail` and `version-management`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the history, these rows included, is read-only data it can return.

## Rollback

Revert the change. The record lives in app config key `external.seen` and the switch in `reactivate.auto`; nothing else reads them. The rows already written stay in the audit table as plain rows.
