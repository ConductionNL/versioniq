# 6. The audit trail is its own table, written best effort and never edited

- Status: Accepted
- Date: 2026-07-23

## Context

Administrators need to see who installed, rolled back or rebound which app, without searching server logs. Server logs rotate, mix all apps and cannot be queried per app or per actor. The installers' backup and restore flow is the most security-critical code in the app, and an audit failure must not break it.

## Decision

- Record version operations in a dedicated database table (`app_versions_audit`) read through an admin-only, paginated API.
- `AuditLogger::record()` is best effort: it catches and logs its own failures, so a failing audit write never aborts a successful install or hides the real error of a failed one. Failures are recorded in the installers' outer error handling, before the error is returned.
- Entries are immutable: there is no update or delete endpoint. The only deletion is the daily `PruneAuditJob`, which removes entries older than the retention setting (default 365 days, minimum 30).
- Messages never contain tokens or secrets.

## Consequences

- The trail is queryable per app and per actor and is useful for accountability, because an administrator cannot edit the record.
- An install can succeed without an audit entry if the audit write fails; this is logged as an error.
- The app keeps a small database footprint of its own alongside app config.

## Source

`openspec/changes/archive/2026-07-23-add-version-audit-trail/design.md` ("Write path: best-effort by construction", "Why a DB table and not nextcloud.log", "Immutability", "Retention"); implemented in `lib/Service/Audit/AuditLogger.php` and `lib/BackgroundJob/PruneAuditJob.php`.
