# Design: autoupdate-default-and-config-file

Read against `development` at 0db8937 (2026-09-27; no code change since 02e1050).

## Context

- `PolicyStore::levelFor()` returns `none` when no policy is stored (`lib/Service/Policy/PolicyStore.php:58-60`); `all()` returns only stored policies (line 68-94).
- `AutoUpdateJob::run()` loops over `PolicyStore::all()` and skips level `none` (`lib/BackgroundJob/AutoUpdateJob.php:105-120`), so an app without a stored policy is never visited. `processApp()` skips pinned apps and apps `isManageableApp()` refuses (line 123-134).
- The kill switch and window live in `AutoUpdateSettingsStore` (`auto_update_enabled`, `auto_update_window`, `lib/Service/AutoUpdate/AutoUpdateSettingsStore.php:28-31`), written by `PUT /api/auto-update/settings` (`lib/Controller/ApiController.php:820-861`).
- `PolicySelector.vue` shows one level per card and `AutoUpdateOverview.vue` lists apps with a policy.
- `autoupdate-security-first` also modifies the requirement `Per-app update policy [MVP]` (it adds a `security` level). Whichever of the two changes archives second must carry both edits into the requirement text.

## Goals and non-goals

Goals: one default for every app, and policies that can live in a file under the admin's version control.

Non-goals: presets across instances, per-app schedules.

## Decisions

### D1. The default is a setting, not a stored policy per app

`auto_update_default_level` (none, patch, minor, all; default none) sits next to the kill switch. `PolicyStore::effectiveLevelFor(appId)` returns the app's own level when stored, else the default. Nothing is written per app, so changing the default changes every app that follows it at once, and "reset to default" is `DELETE /api/app/{appId}/policy`, which already exists.

Alternative considered: writing the default into every app's policy. Rejected: a later default change would have to guess which apps were set by hand.

### D2. The job visits every manageable app

`AutoUpdateJob` loops over the apps `InstallerService::getInstalledApps()` returns with state `enabled`, takes `effectiveLevelFor()`, and skips level none, core, unmanageable and pinned apps as today. With the default at none, the job behaves exactly as now.

### D3. The file shape

```json
{
  "schemaVersion": 1,
  "autoUpdate": { "enabled": true, "window": "01:00-05:00", "defaultLevel": "patch" },
  "policies": { "openregister": "minor", "calendar": "none" }
}
```

`PolicyFile` validates it (known keys only, valid levels, a valid window, app ids in the app id character set) and reports every problem with its JSON path. Import writes through the same stores as the API, records `setBy` as `occ`, and writes one audit row per changed policy. `--dry-run` prints the difference and writes nothing. `--prune` deletes stored policies the file does not name.

### D4. The file mode

When `config.php` holds `'versioniq.policy_file' => '/path/policies.json'`, the job reads and validates that file at the start of every run and uses it instead of the stores. An invalid or unreadable file makes the run do nothing and log an error, never fall back to the database. `GET /api/policies` returns `managedBy: {file: <path>, readAt, error}`, the page shows the levels read-only with that line, and `PUT` and `DELETE` on policies answer 409 naming the file.

Alternative considered: a file inside the app folder. Rejected: an app update replaces that folder.

## Risks and trade-offs

- [An admin sets the default to all and every app updates tonight] → the settings row says how many apps follow the default and at which level, and the kill switch still gates everything.
- [A bad file stops automatic updates] → the run logs why and the page shows the file error on the Automatic updates section.

## Migration

No schema change. The default starts at none, so nothing changes until an admin sets it. Rollback: revert.
