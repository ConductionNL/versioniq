# Design: audit-external-changes

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AppUpdatedListener` (`lib/Listener/AppUpdatedListener.php:48-60`) handles `OCP\App\Events\AppUpdateEvent`, which Nextcloud dispatches after an update through its app manager: the Apps page, `occ app:update`, a server upgrade. It reads the installed version and hands it to `PinDriftHandler`, which acts only when the app is pinned (`lib/Service/Pin/PinDriftHandler.php:54-64`). For an unpinned app nothing is recorded. The event carries the app id only: no old version, no actor, no trigger.
- `PinReconcileJob` (`lib/BackgroundJob/PinReconcileJob.php:38`, `:54-69`) checks pinned apps once a day for changes the event missed.
- Versioniq's own installs do not go through Nextcloud's app manager update: `InstallFinalizer::finalize()` runs the migrations and writes `installed_version` itself (`lib/Service/Installer/InstallFinalizer.php:74-176`), so no `AppUpdateEvent` fires for them. Both installers keep the previous enabled state (`lib/Service/SelectedReleaseInstallerService.php:309`, `:352`; `lib/Service/ExternalReleaseInstallerService.php:155`, `:311`).
- Listeners are registered in `Application::register()` (`lib/AppInfo/Application.php:43-47`). The audit write path is `AuditLogger::record()` (`lib/Service/Audit/AuditLogger.php:59`); operation names must match `^[a-z_]{1,32}$` (`:41`). The History tab prints the operation as stored (`src/components/HistoryPanel.vue:175`).
- One-click enable already exists and goes through Nextcloud's provisioning API with the admin's password (`src/utils/enableApp.ts`).

## Goals and non-goals

Goals: every version change and every disable by an update is in the history with what triggered it; an app left disabled by an update is reported and, when it can run, re-enabled.

Non-goals: vetoing an outside update (no such hook); forcing an incompatible app on (`install-force-incompatible`); forwarding (`audit-attribution-and-forwarding`).

## Decisions

### D1. What Versioniq last saw

`SeenStateStore` keeps `external.seen`: JSON with `serverVersion` and, per app id, `{version, enabled}`. It is written:

- by `InstallFinalizer::finalize()` after a successful Versioniq install, for that app;
- by the recorder after it records a change (D2, D3);
- in full by the hourly job after each run.

The first run stores a baseline and records nothing, so an upgrade to this version does not flood the history.

### D2. Changes Nextcloud reports

`AppUpdatedListener::handle()` keeps calling `PinDriftHandler` as today, and then calls `ExternalChangeRecorder::versionChanged(appId, newVersion)`. When the seen version differs, the recorder writes one `external_update` row: actor the session user's uid, or `system` without one; `from_version` the seen version; `to_version` the new one; `source_id` the app's binding, or null for the App Store; and the trigger from `TriggerDescriber` as the message. A pinned app also keeps its `pin_drift` row: that one says the pin was broken, this one says what changed and how.

`TriggerDescriber` returns:

- on the command line: `occ` and the command's arguments, joined, at most 200 characters, with anything after `--password`, `--token` or `--pass` replaced by `[redacted]`;
- in a web request: the request method and path (for example `POST /settings/apps/update/calendar`), without the query string;
- otherwise `unknown`.

### D3. Changes nobody reported

`ExternalChangeReconcileJob` (`TimedJob`, hourly, registered in `appinfo/info.xml`) compares every app `IAppManager` knows, and the server version, with `external.seen`:

- a version that changed writes `external_update` with the trigger "found by the hourly check: no update event was seen";
- an app that appeared or disappeared writes `external_update` with a null `from_version` or `to_version`;
- an enabled app now disabled goes to D4.

It replaces nothing in `PinReconcileJob`, which keeps its own daily drift pass.

Alternative considered: run this check inside `PinReconcileJob`. Rejected. That job is daily and pin-only by contract (`version-pinning`, "Drift detection"), and an hour matters for a disabled app.

### D4. Disabled by an update, or on purpose

`AppDisabledListener` handles `OCP\App\Events\AppDisableEvent`, and the hourly job finds disables the event missed. A disable without a version change of that app or of the server since the seen state is deliberate, whoever made it: it writes `external_disable` with the actor (the session user, or `system`) and the trigger, and nothing more.

An enabled app found disabled, by the listener or the hourly job, while its own version or the server version changed since the seen state, was disabled by an update. `ReactivationService` then:

1. checks the installed version against the running server with the same `isAppCompatible()` call the installers use;
2. when it runs and `reactivate.auto` is on, enables it with `IAppManager::enableApp()` and writes `reactivate` with status success or failure;
3. otherwise notifies every admin with subject `app_left_disabled`, naming the app and the version change, and either "It runs on this server: enable it" or "It does not run on Nextcloud {major}", with the newest compatible version when the availability snapshot of `inventory-pending-updates` names one.

The card of such an app shows "Disabled by the update to {version}" with the existing Enable action, or the compatible version to install.

Alternative considered: re-enable automatically by default. Rejected. An admin who did not expect it would find an app back on that a server upgrade had switched off on purpose; the switch is one click on the Settings tab.

## Risks and trade-offs

- [The command line can carry a secret] → the describer redacts the values of password and token options and truncates; `AuditLogger` also redacts bearer tokens.
- [A disable during a server upgrade happens before Versioniq's listener is loaded] → the hourly job finds it by comparing with the seen state; the server version change marks it as caused by an update.
- [An admin disables an app on the command line while also upgrading it] → the version changed, so the app is treated as disabled by an update and reported; with automatic re-enable on it would come back. The report names the trigger, and the switch is off by default.
- [Many apps change in one server upgrade] → one row per app is the record the row asks for; notifications are raised only for apps left disabled.

## Migration

No schema change. The first hourly run after upgrade stores the baseline. The job is registered in `info.xml`.
