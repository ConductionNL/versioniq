# Design: audit-attribution-and-forwarding

Read against `development` at 0db8937 (2026-09-27; no code change since 02e1050).

## Context

- `AuditLogger::record()` (`lib/Service/Audit/AuditLogger.php:60-110`) inserts one `AuditEntry` with actor, app, operation, versions, source, status and a sanitised message, best-effort: a failure is logged and never reaches the caller. Operations match `^[a-z_]{1,32}$` (line 41); today they are `install`, `bind_source`, `pin`, `unpin`, `pin_drift` and `settings` (line 33-39).
- The table `app_versions_audit` (`lib/Migration/Version1002Date20260723120000.php:40-80`) has `actor_uid` as 64 characters and no origin.
- The installers take the actor from the session: `SelectedReleaseInstallerService::recordInstallAudit()` (`lib/Service/SelectedReleaseInstallerService.php:437-455`) and the external installer (`lib/Service/ExternalReleaseInstallerService.php:401-421`) fall back to `system`; so do pins (`InstallerService::currentActorUid()`, `lib/Service/InstallerService.php:1037-1039`). The nightly job and `occ` both run without a session user.
- The Settings tab writes an audit row `settings` (`lib/Controller/SettingsController.php:120-134`). `setPolicy()` (`lib/Controller/ApiController.php:752`), `clearPolicy()` (795), `updateAutoUpdateSettings()` (822-861) and `updateAdvisorySettings()` (912-953) write none. The page loads `setBy` and `setAt` for each policy (`src/App.vue:204`) and never shows them.
- `HistoryPanel.vue` renders `actorUid` per row (`src/components/HistoryPanel.vue:171`).
- Nextcloud's `admin_audit` app listens for `OCP\Log\Audit\CriticalActionPerformedEvent` (server `apps/admin_audit/lib/AppInfo/Application.php:82`; the event exists since 22) and writes it to the audit log, whose target (`log_type_audit`: file, syslog, systemd, errorlog) the admin already configures for their collector.
- `OCP\Activity\IManager`, `IProvider` and `ActivitySettings` are the platform's activity API. Versioniq registers no provider (`lib/AppInfo/Application.php:43`).
- `audit-security-reports` adds its own nullable column to the same table through its own migration; the two migrations are independent.

## Goals and non-goals

Goals: every row says where it came from, rule changes are audited, and the history reaches the platform's audit log and activity stream.

Non-goals: token and trusted-source audit, a Versioniq-owned log sender.

## Decisions

### D1. An origin column, set by the caller

A migration adds `origin` (string, 16, nullable) to `app_versions_audit`. `AuditLogger::record()` takes `origin` (`web`, `cli`, `job`, `listener`), default `web`. `installAppVersion()` gains an `origin` argument that the installers pass through; `InstallVersion` passes `cli`, `AutoUpdateJob` passes `job`, `PinDriftHandler` passes `listener`. Old rows read `null`, shown as "unknown". The History tab shows "Automatic", "Command line", "Drift check" or the admin's name, and `GET /api/audit?origin=` filters.

Alternative considered: set the actor to `occ` or `autoupdate` instead of `system`. Rejected: the actor is a user id elsewhere in the fleet's audit conventions, and a pseudo user breaks filters that expect one.

### D2. Rule changes are audited

New operations: `policy_set`, `policy_clear`, `auto_update_settings`, `advisory_settings`. Each carries the value before and after in its message (for example `level: patch -> minor`, `enabled: false -> true, window: 01:00-05:00 -> 02:00-04:00`). `PolicySelector.vue` shows "Set by {user} on {date}" as the selector's tooltip.

### D3. The platform's audit log as the SIEM channel

After a successful insert, `AuditLogger` dispatches `CriticalActionPerformedEvent` with the message `Versioniq: {operation} {appId} {from} -> {to} by {actor} ({origin}), {status}` and those values as parameters. Nothing listens when `admin_audit` is disabled, so nothing changes then. The message goes through the existing sanitiser first, so no token can reach the log.

Alternative considered: a syslog or webhook sender in Versioniq. Rejected: `admin_audit` already ships to file, syslog and journald under the admin's own configuration, and a second sender would duplicate both the configuration and the failure modes.

### D4. The activity stream

`lib/Activity/Provider.php` renders type `versioniq_version_change` for installs, rollbacks, pins, unpins and policy changes; `lib/Activity/Setting.php` puts it under a Versioniq setting that is on for the stream and off for mail by default. `AuditLogger` publishes one activity event per admin (the group `admin`, like the notifications), after the insert, best-effort. With the activity app disabled, publishing is a no-op.

## Risks and trade-offs

- [An activity event per admin on a busy nightly run] → one per install, the same count as today's success notifications; admins can switch the setting off.
- [The audit log grows] → only when `admin_audit` is on, which is the admin's choice for exactly this purpose.

## Migration

One migration adds a nullable column; no data is rewritten. Rollback: revert; the column stays and is ignored.
