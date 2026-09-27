# Design: install-one-click-updates

Read against `development` at 02e1050 (2026-09-27).

## Context

- `requestInstall()` (`src/App.vue:1557-1597`) posts to `POST /api/app/{appId}/versions/{version}/install` with the flags the picker sets (`overridePin`, `pin`, `acceptNewSha`, `allowDowngrade`). `performInstall()` (`src/App.vue:1712`) wraps it with the confirmation dialogs and the result panel.
- The route carries `#[PasswordConfirmationRequired(strict: false)]` (`lib/Controller/ApiController.php:468`). Non-strict confirmation lasts for the platform's confirmation window, so one confirmation covers a run of installs; `ensurePasswordConfirmation()` (`src/App.vue:1068-1094`) asks only when the platform says it must.
- The card already has one one-click action: "Roll back to {version}" (`src/App.vue:2305-2313`, `rollbackToLastKnownGood()` at line 1846).
- The advisory card line shows `recommendedVersion` as text (`src/App.vue:2253-2260`).
- `ChangelogRangePanel.vue` renders the release notes between two versions and is used by the picker.
- `inventory-pending-updates` specifies `GET /api/updates`, which gives each app its `newestCompatibleVersion` and whether it is pinned.

## Goals and non-goals

Goals: one action to update one app, all apps, or to the safe version, through the existing guarded path.

Non-goals: a second install path, batch rollback, stage-by-stage progress.

## Decisions

### D1. No new endpoint

All three actions call `requestInstall()` with the target version and no override flags. A pinned app therefore answers 409 with category `pinned`, and the existing `PinOverrideDialog.vue` takes over, exactly as in the picker. A downgrade can never happen from these buttons, because the targets are newer by construction; the server-side downgrade guard still stands behind that.

Alternative considered: a batch endpoint that installs many apps in one request. Rejected: one PHP request would hold several downloads and migrations, run into the request time limit, and hold the session lock (the issue #160 failure).

### D2. The single confirmation

`QuickUpdateDialog.vue` shows the app, the move (installed to target), the `ChangelogRangePanel` for that range, and an Update button. The advisory button reuses it with the safe version and says which advisory the version resolves.

### D3. Update all runs in the browser, one app at a time

`UpdateAllDialog.vue` lists the apps whose snapshot entry has a newer compatible version, ticked by default except pinned apps (unticked, "Pinned to {version}"). On Update it calls `ensurePasswordConfirmation()` once, then `updateBatch.ts` runs `requestInstall()` for each ticked app in turn, waits for each result, and shows it on the row: updated, failed with the category and hint, or skipped. A failure never stops the batch. Closing the dialog stops the run after the current install, and says so before closing.

Alternative considered: a queued background job. Rejected for this row: the admin asked for an action they watch, and a job would record the installs as `system`, which `audit-attribution-and-forwarding` is still fixing.

### D4. When the snapshot is stale

Each button reads the snapshot. If the install endpoint answers that the version is already installed, the row reads "Already up to date" and the page reloads the snapshot at the end of the batch.

## Risks and trade-offs

- [A long batch outlives the confirmation window] → the next install then gets the platform's confirmation prompt, and the batch waits for it instead of failing.
- [Many apps in maintenance mode one after the other] → each install switches maintenance mode on and off itself (`InstallerService.php:597-600`, `793`); the dialog says users may see short maintenance pauses.

## Migration

None. Rollback: revert.
