# Design: install-bulk-rollback

Read against `development` at 02e1050 (2026-09-27).

## Context

- The only rollback today is per app. After every successful finalize, `InstallFinalizer` writes `lkg.{appId}` with the version, time and source (`lib/Service/Installer/InstallFinalizer.php:169-173`), and the card offers "Roll back to {version}" when the installed version differs (`src/App.vue:2309-2315`, `shouldOfferLkgRollback` in `src/utils/migrationSafety.ts`). `rollbackToLastKnownGood()` (`src/App.vue:1833-1852`) routes through the normal install flow, so the downgrade dialog and its migration diff apply. The record holds one version per app, overwritten on each install.
- `InstallerService::getInstalledApps()` (`lib/Service/InstallerService.php:89`) gives, per managed app, the installed version, state and bound source. That is everything a snapshot needs, read locally.
- `installAppVersion()` (`lib/Service/InstallerService.php:407`) refuses a downgrade without `allowDowngrade` (`lib/Service/InstallerService.php:476`), refuses to move a pinned app without `overridePin` (`lib/Service/InstallerService.php:498`), and reports `orphanedMigrations` for a downgrade in a dry run (`lib/Service/SelectedReleaseInstallerService.php:648-651`, `lib/Service/ExternalReleaseInstallerService.php:252-257`).
- A forge binding keeps the digest of every version it installed (`lib/Service/Source/SourceBinding.php:150`) and the external installer enforces it on a reinstall (`lib/Service/ExternalReleaseInstallerService.php:201-204`). So moving back to a version the instance ran before is checked against the package it ran then.
- A version that left the source can still come from the artifact cache (`ArtifactCache::fetch()`, `lib/Service/Cache/ArtifactCache.php:145`), which the installers already fall back to.
- `install-one-click-updates` (open change, same batch) runs Update all in the browser with `src/utils/updateBatch.ts`: one `requestInstall()` per app, in turn, attributed to the admin, with a stop after the current install. It deliberately does not use a background job.

## Goals and non-goals

Goals: a record of what every app ran at a moment; a plan to move back to it; a run that installs the plan through the standard path, in the browser or on the command line.

Non-goals: reinstalling removed apps or removing added ones; undoing database content; a background run.

## Decisions

### D1. The snapshot

`InstanceSnapshot` holds `id` (a sortable time-based id), `takenAt` (ISO 8601 UTC), `takenBy` (uid or `system`), `reason` (`daily`, `before_auto_update`, `manual`, `before_rollback`), an optional `name`, and `apps`: per app id `{version, sourceId, enabled}` for every app `getInstalledApps()` returns with a state other than `notInstalled`.

`SnapshotStore` writes each one as JSON under `snapshot.{id}` with `IAppConfig`'s lazy flag, so the values load only when read, and keeps an index under `snapshot.index` (`id`, `takenAt`, `reason`, `name`, `appCount`). It keeps the newest 60 unnamed snapshots and up to 20 named ones; a named one stays until an admin deletes it.

Alternative considered: rebuild the state at a date from the audit trail. Rejected. The trail records Versioniq's own installs and pinned-app drift only, so an update made elsewhere to an unpinned app would be missed, and retention prunes old rows.

### D2. When snapshots are taken

- `SnapshotJob` (`TimedJob`, every 24 hours, registered in `appinfo/info.xml`).
- `AutoUpdateJob`, before the first install of a sweep; a sweep that installs nothing takes none.
- An admin, from the History tab (`POST /api/snapshots`, optional name) or `occ versioniq:snapshot`.
- The page, right before a rollback run starts, so the run itself can be undone.

Each is audited as operation `snapshot` with the id and reason in the message.

### D3. The plan

`RollbackPlanner::plan(snapshotId)` compares the snapshot with `getInstalledApps()` now, locally and without a source call, and returns one step per app:

| Step | When |
|---|---|
| `unchanged` | same version |
| `downgrade`, `upgrade` | another version; target and source from the snapshot |
| `pinned` | the app is pinned to a version other than the target; skipped unless the admin ticks "Move the pin" |
| `removed` | in the snapshot, not installed now; left alone |
| `added` | installed now, not in the snapshot; left alone |

`downgrade` and `upgrade` steps are ordered by the time of the app's newest audit row after the snapshot, newest first, so the last change is undone first; apps without such a row follow in alphabetical order. `GET /api/snapshots/{id}/plan` returns it; `GET /api/snapshots?at=<date>` picks the newest snapshot taken on or before the date.

Whether a target is still available is not checked while planning: that would cost a source call per app. The install answers it, and the artifact cache covers a version the source dropped.

### D4. Checking and running on the page

`RollbackPanel.vue` on the History tab lists snapshots with a date picker. `RollbackPlanDialog.vue` shows the plan.

- **Check.** Runs a dry-run install (`requestInstall(..., dryRun)`) for each `downgrade` step in turn and shows its `orphanedMigrations` on the step. It changes nothing.
- **Run.** Takes a `before_rollback` snapshot, asks for the password once, and runs the steps with `updateBatch.ts` from `install-one-click-updates`, with `allowDowngrade=1` on `downgrade` steps (the admin acknowledged the plan) and `overridePin=repin` on ticked `pinned` steps, and with the snapshot's source as the one-off source. Unlike Update all, a rollback stops at the first failure and offers "Continue with the rest", because apps often depend on each other. Closing the dialog stops after the current install.
- Before the first step and after the last, the page calls `POST /api/snapshots/{id}/run-log`, which writes a `bulk_rollback` audit row: started, or finished with how many steps succeeded, failed and were skipped.

Alternative considered: a batch endpoint or a queued job. Rejected for the reasons `install-one-click-updates` gives: one request cannot hold several downloads and migrations, and a job would record the installs as `system`.

### D5. The commands

- `occ versioniq:snapshot [--name=]` takes one.
- `occ versioniq:snapshots [--json]` lists them.
- `occ versioniq:rollback <snapshotId> | --to=<YYYY-MM-DD>` prints the plan and asks for confirmation, or runs without asking with `--yes`. `--dry-run` runs the check of D4, `--move-pins` includes pinned steps, `--continue` goes on after a failure. It takes a `before_rollback` snapshot, writes the two `bulk_rollback` rows, and exits 0 when every step succeeded, else with the install exit code of the first failed step.

## Risks and trade-offs

- [A long run in the browser is interrupted] → each step is a complete install or a clean revert; the audit rows and a new plan show where it stopped, and the `before_rollback` snapshot allows undoing the part that ran.
- [Moving several apps back orphans migrations in each] → the check lists them per app before anything runs, and each step keeps the server-side downgrade guard.
- [Snapshots grow app config] → lazy keys are not loaded per request, and the caps keep at most 80.
- [A step fails because the target left its source and was never cached] → the step fails with the installer's "not found" answer, the run stops, and the plan names the app.

## Migration

No schema change. The daily job is registered in `info.xml`, so Nextcloud adds it on upgrade, and the first snapshot is taken on its first run.
