---
kind: code
---

# Proposal: install-live-progress

## Why

An install through Versioniq can take a minute or more: a download, a signature check, an extraction, database migrations. All that time the admin sees a spinner that reads "Installing...". They cannot tell a slow download from a stuck migration, so they reload the page, which is the one thing they should not do while maintenance mode is on. The outcome at the end is clear; the minute before it is not.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `ins-progress` | partial, built | The missing half: no live stage-by-stage progress. The result panel after the install is already clear (status, transition, mode, failure category and stage). |

### Demand

No demand row. Two competitors are rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- Renovate: "Each run logs its progress and the dashboard shows each update's state (pending, errored, open); Mend cloud shows job logs per repository (docs/usage/mend-hosted/overview.md:26-31)".
- Easy Updates Manager, through WordPress core: "update screens print each step and the outcome (Downloading update, Unpacking, Plugin upgraded successfully, https://wordpress.org/documentation/article/dashboard-updates-screen/)".
- Nextcloud is partial: "a loading icon per row and an error toast ... the standalone updater reports 12 named steps with download progress (nextcloud/updater@v34.0.4 lib/UpdateCommand.php:37-51)".

## What changes

- Both installers report each stage as it starts and ends: resolving the release, downloading (with bytes when the server says how many), checking the signature or checksum, extracting, validating `info.xml`, comparing migrations, backing up, swapping files, running migrations and repair steps, restoring on failure.
- While the install request runs, the page polls a small progress endpoint and shows the stages as a checklist, with the running stage and how long it has taken.
- `occ versioniq:install` prints the same stages as they happen.
- The page warns against closing or reloading while maintenance mode is on.

## Scope

In scope: the progress reporter, its short-lived store, the progress endpoint, releasing the session lock during an install, the checklist, the CLI output, tests.

Out of scope:
- Progress for a batch. `install-one-click-updates` shows one outcome per app; each of those installs gets this checklist.
- Changing any install step. Progress only observes.

## Impact

- New: `lib/Service/Installer/InstallProgress.php`, `src/components/InstallProgress.vue`.
- Changed: `lib/Service/SelectedReleaseInstallerService.php` and `lib/Service/ExternalReleaseInstallerService.php` (report at each existing debug stage), `lib/Service/Installer/InstallFinalizer.php` (migrations and repair steps), `lib/Controller/ApiController.php` (progress route; the install route takes a progress token and releases the session), `lib/Command/InstallVersion.php`, `src/App.vue`, `l10n`.
- ADDED requirements in `version-management` and `cli-commands`.

### MCP coverage

No MCP surface in this change: it observes installs that stay a human action.

## Rollback

Revert the change. Progress lives in the distributed cache for ten minutes and needs no clean-up.
