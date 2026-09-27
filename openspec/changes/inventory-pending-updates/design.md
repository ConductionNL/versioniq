# Design: inventory-pending-updates

Read against `development` at 02e1050 (2026-09-27).

## Context

- `InstallerService::getInstalledApps()` (`lib/Service/InstallerService.php:89`) already returns `installedVersion` and `state` for every card. `src/App.vue:2206-2317` renders the card from it and never prints the version.
- `InstallerService::getAppVersions()` (`lib/Service/InstallerService.php:185`) resolves the bound source and lists versions, newest first, each with a nullable `serverCompatible`. It costs one external call per app, except that the App Store catalogue is fetched once and cached for an hour for every app (`AppStoreSource::cacheCatalogueEntries`, `lib/Service/Source/AppStoreSource.php:490`).
- `AutoUpdateJob::processApp()` (`lib/BackgroundJob/AutoUpdateJob.php:123`) already works out a candidate per app through `CandidateSelector::select()` and throws the answer away.
- Issue #160: `GET /api/advisories` used to correlate live, did not answer within 120 s on an 88-app instance, and held the session lock so `/api/pins` never ran. The fix was a sweep in `AdvisoryRefreshJob` writing to `AdvisoryResultStore`, read by the endpoint. A live "what is behind" list has the same cost, so it gets the same shape.

## Goals and non-goals

Goals: one stored answer per app to "what runs, what is newest, how far behind", read by the card, the filter, the lag flag and the CLI.

Non-goals: installing from the list (see `install-one-click-updates`), notifying (see `releases-new-release-alerts`), pre-releases (see `releases-channel-and-prereleases`).

## Decisions

### D1. A sweep and a snapshot, not a live list

`AvailabilityRefreshJob` (`TimedJob`, every 6 hours, registered in `appinfo/info.xml` `<background-jobs>`) calls `AvailabilityService::sweep(float $budgetSeconds)` and hands the result to `AvailabilityResultStore::save()`. The store copies `AdvisoryResultStore` (`lib/Service/Advisory/AdvisoryResultStore.php`): JSON under app config key `availability.results`, unix time under `availability.results.checkedAt`, a snapshot that fails to encode keeps the previous one, and `read()` returns `checkedAt: null` when no sweep has run.

Alternative considered: extend `AdvisoryRefreshJob`. Rejected, because the advisory sweep already spends its 600 s budget on two calls per app, and a failure in one would hide the other.

### D2. What the sweep stores per app

For each app `getInstalledApps()` returns with a state other than `notInstalled`, and `isManageableApp()` true:

| Field | Meaning |
|---|---|
| `installedVersion` | from `getInstalledApps()` |
| `newestVersion` | highest listed version that is plain `major.minor.patch` (the `CandidateSelector` pattern) |
| `newestCompatibleVersion` | highest such version whose `serverCompatible` is not `false` |
| `linesBehind` | number of distinct `major.minor` lines above the installed line, among compatible versions |
| `pinned` | true when `PinStore::get()` returns a pin |
| `sourceId` | the bound source the list came from |
| `error` | the source error, or null |

An app is "behind" when `newestCompatibleVersion` is newer than `installedVersion`. A pinned app is swept like any other, so its badge stays. A version the server cannot run never counts as an update, because installing it would be refused (`SelectedReleaseInstallerService.php:334-337` checks compatibility on install).

Alternative considered: count every newer version, not release lines. Rejected: an app that ships a patch a week would read "12 behind" while being one release line behind, and the N-1 rule in the tender counts releases, not patches.

### D3. The lag limit

`InstanceSettings` (`lib/Service/Settings/InstanceSettings.php`) gains `update.max_lines_behind`, an integer from 0 to 10, empty meaning off. `InstanceSettingsPanel.vue` gets one number field for it. `GET /api/updates` returns it, and the page flags an app as outside the policy when `linesBehind` is greater than the limit. The flag is computed on read, so changing the limit needs no new sweep.

### D4. The endpoint

`GET /api/updates` on `ApiController`, admin-only through the existing `isAdmin()` guard (`lib/Controller/ApiController.php:1386`), returns `{updates: {<appId>: {...D2}}, checkedAt, maxLinesBehind}`. It reads only; it never calls a source.

### D5. The page

- `App.vue` loads `/api/updates` next to `/api/advisories` in the non-blocking group after `loadApps()` (`src/App.vue:1868-1930`), with `BACKGROUND_FETCH_TIMEOUT_MS`.
- Each card shows "Installed {version}" under the id, for every app with an installed version.
- A card with a newer version shows an "Update available: {version}" badge; a pinned one reads "Update available: {version}, held by the pin".
- A card past the limit shows "Outside the update policy: {n} releases behind".
- The filter panel (`src/App.vue:2186`) gets a select next to Core apps: all apps, apps with an update, apps outside the policy.
- A freshness line says when updates were last checked, like the advisory line, and says so plainly when no sweep has run yet.

### D6. The command

`lib/Command/ListUpdates.php`, `occ versioniq:updates`, registered in `appinfo/info.xml` `<commands>`. It prints the snapshot as a table, or as JSON with `--json`. `--refresh` runs `AvailabilityService::sweep()` first and saves it. `--outside-policy` lists only apps past the limit. Exit code 0 when it printed, 1 when the snapshot is empty and `--refresh` was not given, so a script can tell "nothing behind" from "never checked".

## Risks and trade-offs

- [The snapshot is up to 6 hours old] → the page shows `checkedAt`, and `occ versioniq:updates --refresh` gives a fresh answer on demand.
- [A forge-bound app costs one GitHub call per sweep, and GitHub rate limits anonymous calls at 60 an hour] → the sweep honours a budget like the advisory sweep and records the source error per app, so an app it could not reach shows "Not checked" instead of "Up to date".
- [Release-line counting misreads apps that do not use semver] → such versions never match the pattern, so the app shows its installed version and no badge, never a wrong count.

## Migration

No schema change. The new job is registered in `info.xml`, so Nextcloud adds it on upgrade. Rollback: revert; the three app config keys are inert.
