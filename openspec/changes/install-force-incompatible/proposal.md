---
kind: code
---

# Proposal: install-force-incompatible

## Why

An app often runs fine on a new Nextcloud major before its publisher raises the declared maximum. Nextcloud lets an admin force such an app past its maximum, and since August 2026 lifts that override again at the next major upgrade (nextcloud/server PR 63446), so a forced app is checked afresh on every major. Versioniq honours an override someone else set, but cannot set one. An admin who picks "Not for this server version" in the picker gets a refusal and has to leave Versioniq to force the app.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `ins-force-incompatible` | partial, built | The missing half. Both installers already honour the platform's `app_install_overwrite` list (`lib/Service/SelectedReleaseInstallerService.php:334`, `lib/Service/ExternalReleaseInstallerService.php:580`) and refuse an incompatible version otherwise. Nothing in Versioniq sets the override, and nothing lifts one Versioniq set at the next major upgrade. |

### Demand

- `ins-force-incompatible`: changelog, https://github.com/nextcloud/server/pull/63446. Nextcloud clears the override on a major upgrade; the matrix evidence notes the backport to stable34 in #63633. Versioniq supports Nextcloud 32 to 34 (`appinfo/info.xml`), so on a server without that change Versioniq has to lift its own override.

### Competitors rated yes (evidence quoted from the matrix)

- `ins-force-incompatible`, Nextcloud rated yes: "Force enable via the UI and occ app:install ,force records app_install_overwrite (apps/appstore/lib/Controller/ApiController.php:167-169, core/Command/App/Install.php:31-57); since 2026-08 the override is cleared on a major upgrade (lib/private/Updater.php:215-218, backported to stable34 in #63633)." No other competitor is rated yes.

## What changes

- A version whose declared maximum is below the running server gets an "Install anyway" action in the picker. A version that needs a newer server than this one gets no such action: an override cannot help it, and the picker says so.
- "Install anyway" explains the risk, asks for the password, adds the app to `app_install_overwrite`, and installs through the standard path. When the install fails, Versioniq removes the entry again if it added it.
- A disabled app that Nextcloud will not enable because of its maximum gets "Enable anyway", which sets the same override and then enables it.
- Versioniq records every override it sets with the server major it was set on, and shows it on the app card: "Forced to run on Nextcloud 32. Lifted at the next major upgrade."
- After a major upgrade, Versioniq lifts its own overrides. If the installed version still does not declare support for the new major, Versioniq disables it and tells admins, offering "Enable anyway" again. On a server that already cleared the list itself, Versioniq only tidies its own record.
- `occ versioniq:install --force-incompatible` does the same from the command line.

## Scope

In scope: the override on install and on enable, the record of overrides Versioniq set, the lift after a major upgrade, the card notice, the notification, the CLI flag, tests.

Out of scope:
- Overrides set by someone else, through Nextcloud's own force enable or by editing `config.php`. Versioniq keeps honouring them and never removes them.
- Forcing past a minimum. Nextcloud's override only ignores the maximum, so a version that needs a newer server is never offered.
- Knowing the range of a forge release that was never downloaded. `inventory-upgrade-readiness` specifies that lookup; until it lands such a version shows no compatibility and no "Install anyway".

## Impact

- New: `lib/Service/Installer/ForceOverrideStore.php`, `lib/BackgroundJob/ForceOverrideReconcileJob.php`, `src/dialogs/ForceIncompatibleDialog.vue`.
- Changed: `lib/Service/InstallerService.php` (a `forceIncompatible` argument), `lib/Service/Source/AppStoreSource.php` and `lib/Service/InstallerService.php` (`serverTooNew` next to `serverCompatible`), `lib/Controller/ApiController.php` (the install parameter and `POST /api/app/{appId}/force-enable`), `lib/Command/InstallVersion.php` (the flag), `lib/Service/Audit/AuditLogger.php` (operation `force_incompatible`), `lib/Notification/Notifier.php` (subject `force_lifted`), `appinfo/info.xml` (job), `src/App.vue` and `src/components/ServerCompatBadge.vue`, `l10n/en` and `l10n/nl`.
- ADDED requirements in `version-management` and `cli-commands`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; forcing an incompatible version is a write it would gate.

## Rollback

Revert the change. Versioniq's records live in app config keys `force_incompatible.{appId}`. The entries it added to `app_install_overwrite` in `config.php` stay; the release notes say to remove them with `occ config:system:delete app_install_overwrite <index>` or leave them to Nextcloud's own lift at the next major.
