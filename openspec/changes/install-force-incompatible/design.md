# Design: install-force-incompatible

Read against `development` at 02e1050 (2026-09-27).

## Context

- The signed installer reads `app_install_overwrite` from the system config and passes `$ignoreMax` to `isAppCompatible()` and to `OC_App::checkAppDependencies()` (`lib/Service/SelectedReleaseInstallerService.php:334-346`). On a refusal it restores the previous files and reports a clean revert (`lib/Service/SelectedReleaseInstallerService.php:346-349`).
- The external installer does the same with `\OC_App::isAppCompatible()` (`lib/Service/ExternalReleaseInstallerService.php:580-602`).
- The check runs on a real install only. A dry run of an App Store package skips it (matrix note on `saf-test-before-apply`).
- `installAppVersion()` (`lib/Service/InstallerService.php:407`) already writes one system value around an install: it sets `maintenance` and clears it in `finally` (`lib/Service/InstallerService.php:597-600`, `:791-795`). So a system-config write around an install has a precedent and a place.
- App Store versions carry `serverCompatible` from `platformVersionSpec` (`lib/Service/Source/AppStoreSource.php:663`); a cached forge version gets it from its own `info.xml` (`lib/Service/InstallerService.php:334-357`). `satisfiesPlatformSpec()` answers true or false, not which bound failed (`lib/Service/Source/AppStoreSource.php:663-694`).
- The picker shows "Not for this server version" (`src/components/ServerCompatBadge.vue:19-27`). A refused install reports category `incompatible` (`lib/Service/Installer/FailureClassifier.php:47`) and CLI exit code 7 (`lib/Command/InstallVersion.php:54`).
- One-click enable calls Nextcloud's provisioning API, which has no force (`src/utils/enableApp.ts`).

## Goals and non-goals

Goals: set the override from Versioniq for a version only its maximum keeps out; lift what Versioniq set after a major upgrade, as Nextcloud now does; show it while it holds.

Non-goals: touching overrides others set; forcing past a minimum.

## Decisions

### D1. Which versions may be forced

Nextcloud's override ignores the maximum only; a minimum above the server still refuses. So the picker offers "Install anyway" only when the server is above the version's maximum. `AppStoreSource::satisfiesPlatformSpec()` gets a sibling, `serverAboveMaximum(serverVersion, spec)`, true when every lower bound holds and an upper bound does not. `getAppVersions()` stamps `serverTooNew` on entries whose `serverCompatible` is false, for App Store versions and cached forge versions alike. A version with `serverCompatible: false` and `serverTooNew: false` reads "Needs a newer Nextcloud; cannot be forced."

### D2. Setting the override on install

`installAppVersion()` gains `bool $forceIncompatible = false`. When true and the run is not a dry run:

1. Before the installer runs, `ForceOverrideStore::apply(appId)` adds the app id to `app_install_overwrite` if it is not there, and remembers whether it added it.
2. On success it records `force_incompatible.{appId}`: `{version, serverMajor, setBy, setAt, addedByVersioniq}` and writes an audit row, operation `force_incompatible`.
3. On any failure, in the same `finally` that clears maintenance mode, it removes the entry again when it added it.

A dry run with the flag reports `forceIncompatible: true` and changes no config.

The HTTP route reads `forceIncompatible=1` next to `allowDowngrade`, password-confirmed like every install. `ForceIncompatibleDialog.vue` says what the override does, that the publisher has not declared support for this Nextcloud, and that Versioniq lifts it at the next major upgrade.

Alternative considered: pass `$ignoreMax` to the installers without touching `config.php`. Rejected. Nextcloud itself reads `app_install_overwrite` again when it enables the app and at the next upgrade; an override only Versioniq knows about would be refused one step later.

### D3. Enable anyway

`POST /api/app/{appId}/force-enable` (admin-only, password-confirmed) applies the override as in D2, then enables the app with `IAppManager::enableApp()`. It is offered on a disabled app card when the installed version's `serverTooNew` is true. A failure removes the entry again if Versioniq added it.

### D4. Lifting after a major upgrade

`ForceOverrideReconcileJob` (`TimedJob`, hourly, registered in `appinfo/info.xml`) compares the running server major with each record's `serverMajor`. When the server major is higher:

- If the app id is still in `app_install_overwrite` and the record says Versioniq added it, it removes it.
- If the entry is already gone, Nextcloud's own lift (PR 63446) ran; nothing else is touched.
- It then checks the installed version against the new server with the same `isAppCompatible()` call the installers use. When the version still does not declare support, it disables the app with `IAppManager::disableApp()`, the state Nextcloud's updater leaves an incompatible app in.
- It deletes the record, writes an audit row (`force_incompatible`, "lifted after the upgrade to Nextcloud {major}"), and notifies admins with subject `force_lifted`, naming the app and whether it was disabled. The Apps tab then offers "Enable anyway" for the new major.

Alternative considered: lift inside the upgrade. Rejected. Nextcloud offers apps no public event before its updater checks app compatibility, and Versioniq's own repair steps run only when Versioniq itself is upgraded.

### D5. Showing it

`GET /api/apps` adds `forcedOn` (the major) per app with a record. The card shows "Forced to run on Nextcloud {major}. Lifted at the next major upgrade." An override someone else set shows "Nextcloud's compatibility check is overridden for this app", without a lift promise.

## Risks and trade-offs

- [A forced app breaks the instance] → the dialog says so before the password; the install still runs with backup and restore, and the last-known-good rollback applies.
- [An hour passes between the upgrade and the lift, with the app enabled on an unchecked major] → on servers with PR 63446 Nextcloud lifts it during the upgrade; on older ones the window is at most an hour of cron, and the notification names the app.
- [Two admins edit `app_install_overwrite` at the same time] → the store reads the list, changes one entry and writes it back in one call; it never rewrites entries it did not add.
- [`IAppManager::enableApp()` refuses for another reason, such as a missing PHP extension] → the dialog shows Nextcloud's message and the override is removed again.

## Migration

No schema change. The job is registered in `info.xml`. Overrides that existed before this change have no record, so Versioniq never lifts them.
