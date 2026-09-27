# Design: audit-security-reports

Read against `development` at 02e1050 (2026-09-27).

## Context

- The audit table `app_versions_audit` has `actor_uid`, `app_id`, `operation`, `from_version`, `to_version`, `source_id`, `status`, `message` and `created_at`, with indexes on (`app_id`, `created_at`) and `created_at` (`lib/Migration/Version1002Date20260723120000.php:41-83`). The table name stays on the old app id on purpose (`lib/Db/AuditEntryMapper.php:26-37`).
- `AuditLogger::record()` (`lib/Service/Audit/AuditLogger.php:59`) is the single write path, best effort, for any operation matching `[a-z_]{1,32}` (`:41`, `:69-76`). Both installers write `install` entries with the session user or `system` (`lib/Service/SelectedReleaseInstallerService.php:437-455`, `lib/Service/ExternalReleaseInstallerService.php:401-421`).
- `GET /api/audit` (`lib/Controller/ApiController.php:1258-1275`) pages through `AuditEntryMapper::findPage()` (`lib/Db/AuditEntryMapper.php:50-64`), newest first, filtered by app only. `HistoryPanel.vue` loads pages of 50 (`src/components/HistoryPanel.vue:27`, `:65-98`).
- Rows are immutable: the spec forbids any update or delete outside the retention prune (`openspec/specs/audit-trail/spec.md`, "Audit entries are immutable and admin-readable"). `PruneAuditJob` keeps `audit_retention_days`, default 365 and at least 30 (`lib/BackgroundJob/PruneAuditJob.php:36-39`).
- `AdvisoryRefreshJob::run()` saves the new snapshot before it notifies (`lib/BackgroundJob/AdvisoryRefreshJob.php:83-89`). `AdvisoryResultStore::save()` replaces the previous snapshot (`lib/Service/Advisory/AdvisoryResultStore.php:60-76`). Rows carry `installedVersion`, `state`, `advisories` with severity, and `error` when the app was not reached (`lib/Service/Advisory/AdvisoryService.php:196-212`).
- `ServerVersionProvider::current()` returns the running server version (`lib/Service/Advisory/ServerVersionProvider.php:40`).

## Goals and non-goals

Goals: a durable record of when each advisory affected the instance and how it ended; server updates in the same history; a period, security-only view with export; a per-severity remediation report against deadlines, with a monthly trend.

Non-goals: deadlines themselves (see `autoupdate-security-first`), dismissals (see `advisories-triage`), updates made outside Versioniq (see `audit-external-changes`), scheduled delivery.

## Decisions

### D1. Exposure is tracked by comparing snapshots, and written as audit entries

`AdvisoryExposureTracker::track(array $snapshot, int $checkedAt)` runs in `AdvisoryRefreshJob` right after `save()`. It keeps the working set of open pairs in app config key `advisory.exposures`: `{"{target}|{advisoryId}": {openedAt, severity, version}}`.

- A pair active in the new snapshot and absent from the set is opened: one `advisory_open` entry (actor `system`, `app_id` the app or `:server`, `to_version` the installed version, `advisories` the id and severity).
- A pair in the set and not active in the new snapshot is resolved, but only when the new row has no `error`; an unreached app keeps its pairs open. The `advisory_resolved` entry records `from_version` (the version when opened) and `to_version` (the installed version now) and a `message` with how: `update` when the installed version changed, `dismissed` when `advisories-triage` dismissed it, `withdrawn` when the advisory is no longer in the target's history, `not_affected` otherwise.
- On the first run there is no set. Every active pair is opened with the message `tracking started`, and the report leaves those pairs out of time-to-fix figures because their real start is unknown.

Alternative considered: a new table of exposures. Rejected: Versioniq keeps its database to the `pats` and `audit` tables, and the audit table already has retention, immutability and an admin read path. The open set in app config is working state; the entries are the record.

### D2. One nullable column

A migration adds `advisories` (TEXT, nullable) to `app_versions_audit`: a JSON list of `{id, severity}`. `AuditEntry` gains the field and `AuditLogger::record()` an optional last parameter. Old rows read as null. The message stays free text for people.

Alternative considered: put the advisory ids in `message`. Rejected: the report would parse prose, and a message is truncated at 4000 characters (`AuditLogger.php:42`).

### D3. Server updates

The tracker compares `ServerVersionProvider::current()` with `advisory.last_server_version`. When they differ it writes `server_update` (actor `system`, `app_id` `:server`, `from_version` the stored one, `to_version` the current one, message "Detected by the advisory check"), then stores the current one. The first run only stores it. The entry's time is when the change was seen, at most one check interval after the upgrade, and the page says so.

### D4. Which installs are security updates

An `install` or `server_update` entry with status `success` is a security update when an `advisory_resolved` entry with how `update` exists for the same `app_id` and the same `to_version`, created after it and before the next install of that app. `SecurityReportService` joins these in PHP over the period's entries; the rows themselves are never changed. The fixed advisories are taken from those resolved entries. The time of the fix is the install's time, not the time the check noticed it.

### D5. The History tab and the export

`GET /api/audit` gains `from` and `to` (ISO dates, inclusive, in the admin's time zone sent by the page), `securityOnly` and `operation`. With `securityOnly` the controller returns the D4 set with the fixed advisories per row. `AuditEntryMapper::findBetween()` uses the `created_at` index. `HistoryPanel.vue` gets a from and to date field, a "Security updates only" checkbox and "Export CSV" and "Export JSON" buttons. The export fetches every page for the filter (at most 5000 rows, and says so when cut) and builds the file in the browser, with the columns shown on screen plus the fixed advisory ids.

### D6. The remediation report

`GET /api/reports/security?from=&to=` (admin-only, a new `ReportController` guarded like `SettingsController`) returns, for the period:

- `securityUpdates`: the D4 rows;
- `installs`: counts of `install` entries by status;
- per severity (`critical`, `high`, `medium`, `low`, `unknown`): advisories opened, resolved by update, resolved by dismissal, resolved within the deadline, open past the deadline at the end of the period, open exceptions (active dismissals), and the median days from open to resolved;
- `trend`: the same shares per calendar month for the twelve months ending with `to`, cut to the retention window;
- `deadlines`: the patch policy deadlines used, or null per severity;
- `trackingSince` and `retentionDays`, so the page can say what the figures cover.

"Within the deadline" means resolved (by update or dismissal) no later than `openedAt + deadlineDays`. Pairs opened with `tracking started` count in the opened and resolved totals but not in the time figures. Without a deadline for a severity, the within-deadline share is null and the page says to set one in the patch policy.

`SecurityReportPanel.vue` on the History tab shows the period picker (preset to last month), the figures per severity as a table, the monthly trend as a table, and "Export CSV".

## Risks and trade-offs

- [A source that fails for days makes resolutions late] → an unreached app keeps its pairs open, so a failure never counts as a fix, and the Advisories tab already names the apps a check could not reach.
- [The first month has no real open dates] → `tracking started` pairs are left out of the time figures and the page says since when tracking runs.
- [Retention shorter than the trend] → the trend stops at the retention window and says so; the setting is on the Settings tab.
- [Reading a year of history for the trend] → bounded by retention (at most 3650 days) and by the `created_at` index; the report is computed on request for one admin, never on page load of the Apps tab.

## Migration

One schema step: a nullable TEXT column on `app_versions_audit`, added with `hasColumn` guards so a re-run does nothing. No data is back-filled. Tracking starts at the first advisory check after the upgrade. Rollback: revert the code; the column can stay.
