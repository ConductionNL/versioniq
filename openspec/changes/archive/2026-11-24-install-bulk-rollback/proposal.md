---
kind: code
---

# Proposal: install-bulk-rollback

## Why

When a night of automatic updates, or an admin's round of updates, breaks something, the question is rarely which one app broke. The admin wants the instance back the way it was yesterday. Versioniq can roll back one app at a time, to one version: the last known good. It keeps no record of what every app ran at a given moment, and it has no way to move several apps back in one go.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `ins-bulk-rollback` | no | No multi-app or point-in-time rollback. The page installs one app at a time, and the last-known-good record holds one version per app. |

### Demand

No demand row. The row is in the product's core area (install).

### Competitors rated yes (evidence quoted from the matrix)

No competitor is rated yes. Nextcloud, OSV-Scanner and Easy Updates Manager are rated no; Renovate and Dependabot are rated unknown.

## What changes

- Versioniq takes snapshots of the whole instance: for every app it manages, the installed version, the source and whether it is enabled. It takes one every day, one before the nightly job installs anything, and one whenever an admin asks.
- On the History tab an admin picks a snapshot, or a date, which picks the latest snapshot on or before it. The page shows the plan: per app, whether it moves back, moves forward, stays, is pinned elsewhere, or was removed or added since.
- An admin can check the plan first: a dry run per app that moves back, which lists the database migrations the move would orphan, the same diff the downgrade dialog shows today.
- An admin runs the plan with one password confirmation. The page installs one app at a time through the standard installer, in the reverse order the apps changed since the snapshot, and stops at the first failure unless the admin continues. Every step is an ordinary install with its own audit row, and the run itself is recorded at start and end.
- `occ versioniq:snapshot`, `occ versioniq:snapshots` and `occ versioniq:rollback` do the same from the command line, where a long run is not bound to a browser.

## Scope

In scope: the snapshot store, the three snapshot moments, the plan, the dry-run check, the page run, the audit rows, the commands, tests.

Out of scope:
- Reinstalling an app that was removed since the snapshot, or removing one added since. The plan lists both and leaves them alone.
- Rolling back database content. Nextcloud migrations only run forward; the plan names the migrations each move would orphan, as the downgrade dialog does today (`migration-safety`).
- Rolling back several instances at once. `install-multi-instance-promotion` connects instances; each instance rolls itself back.
- A run in a background job. The page runs the steps the way `install-one-click-updates` runs Update all, so each install is attributed to the admin who started it.

## Impact

- New: `lib/Service/Snapshot/InstanceSnapshot.php`, `lib/Service/Snapshot/SnapshotStore.php`, `lib/Service/Snapshot/RollbackPlanner.php`, `lib/BackgroundJob/SnapshotJob.php`, `lib/Controller/SnapshotController.php`, `lib/Command/TakeSnapshot.php`, `lib/Command/ListSnapshots.php`, `lib/Command/RollbackToSnapshot.php`, `src/components/RollbackPanel.vue`, `src/dialogs/RollbackPlanDialog.vue`.
- Changed: `lib/BackgroundJob/AutoUpdateJob.php` (a snapshot before the first install of a sweep), `lib/Service/Audit/AuditLogger.php` (operations `snapshot` and `bulk_rollback`), `appinfo/info.xml` (job and commands), `src/components/HistoryPanel.vue` or `src/App.vue` (the rollback section on the History tab), `l10n/en` and `l10n/nl`.
- ADDED requirements in `migration-safety` and `cli-commands`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; listing snapshots and plans is read-only, and a rollback run is a write it would gate.

## Rollback

Revert the change. Snapshots live in lazy app config keys `snapshot.{id}` with an index under `snapshot.index`; nothing else reads them, and `occ config:app:delete` removes them. The installs a run made are ordinary installs and stay recorded in the audit trail.
