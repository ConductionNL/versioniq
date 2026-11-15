---
kind: code
---

# Proposal: safety-rollback-backup-and-preflight

## Why

Four rows of the competitor parity matrix (`openspec/parity/capabilities.json`, versioniq) are marked built, but their own notes say a part is missing. The boards VqApps and VqInstalleren draw the whole row, and the boards are the UI canon (decision 130). This change plans the missing halves.

| Row | Built half | Missing half, as the board draws it |
|---|---|---|
| `ins-rollback` | One click back to the last version Versioniq finalized (`src/App.vue:2210`, `lib/Service/Installer/InstallFinalizer.php:169`). | That record is overwritten on every successful install, so after an update there is no one click back to the version before it. VqApps draws both: "Terugzetten naar 0.4.44" (the version before the last update) and "Terugzetten naar de laatst goed afgeronde versie (0.4.46)". |
| `saf-preflight` | The app folder's parent must be writable (`lib/Service/Installer/EnvironmentCheck.php:60`). | No disk space check. VqInstalleren draws "Omgeving gecontroleerd: 4,2 GB vrij, map apps-extra/procest is schrijfbaar". |
| `saf-backup-before` | The app folder is moved aside and restored on failure, then deleted (`lib/Service/SelectedReleaseInstallerService.php:714-751`). | No database backup and nothing is kept. VqInstalleren draws "Back-up gemaakt: database en appmap, 182 MB, bewaard tot 15 okt". |
| `saf-finalize-recovery` | On a failed finalize the files are restored and the outcome is installed-but-broken. | Partly applied migrations stay. VqInstalleren draws "Installatie herstellen" and "Terugzetten naar 0.4.48" after a broken finalize. |

## What changes

- The last-known-good record keeps the version before it as `previous`. The app card offers both rollback targets when they differ.
- The pre-flight check measures free disk space in the apps folder and the temp folder and refuses an install that does not fit.
- Before a real install Versioniq keeps a restorable backup: the old app folder and a dump of the app's own database tables, kept for a set number of days (default 14).
- "Restore installation" after a broken finalize puts back both the folder and the tables from that backup.

## Scope

In scope: the four halves above, their settings, strings and tests.

Out of scope: whole-instance backups (the admin's own backup tool); a backup of data the app keeps outside its own tables (files, other apps' tables).

## Impact

- Changed: `lib/Service/Lkg/Lkg.php`, `lib/Service/Lkg/LkgStore.php`, `lib/Service/Installer/InstallFinalizer.php`, `lib/Service/Installer/EnvironmentCheck.php`, `lib/Service/Installer/FailureClassifier.php` (category `preflight_space`), `lib/Service/SelectedReleaseInstallerService.php`, `lib/Service/ExternalReleaseInstallerService.php`, `lib/BackgroundJob/PruneAuditJob.php` (prunes expired backups), `lib/Controller/ApiController.php` (restore route), `src/App.vue`, `src/components/InstallResultNotices.vue`, l10n.
- New: `lib/Service/Installer/AppBackupStore.php`, `lib/Service/Installer/AppTableDumper.php`.
- ADDED requirements in `migration-safety` and `version-management`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. Kept backups live under the app data folder `appdata_<instanceid>/versioniq/backups`; delete that folder after reverting.
