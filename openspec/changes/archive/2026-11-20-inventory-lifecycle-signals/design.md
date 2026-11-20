# Design: inventory-lifecycle-signals

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AppStoreSource::listVersions()` (`lib/Service/Source/AppStoreSource.php:101-118`) returns the error "App is not available in the Nextcloud App Store." when the catalogue has no entry for the app. `InstallerService::getAppVersions()` (`lib/Service/InstallerService.php:210-216`) already rewrites that error for shipped apps. A store-bound app that is not shipped and gets this error has been removed from the store.
- The App Store catalogue entry carries per release a `created` time and per app `lastModified`. `normalizeVersions()` (`AppStoreSource.php:586-640`) does not keep them yet; `releases-version-facts` adds the release date.
- `ForgeReleaseSource::fetchReleases()` (`lib/Service/Source/ForgeReleaseSource.php:285`) reads only the releases endpoint. GitHub and Forgejo both return `archived` on `GET /repos/{owner}/{repo}`.
- Notifications are raised in services and rendered by `lib/Notification/Notifier.php:57` per subject (`pin_drift`, `pat_expiring`, `auto_update_success` and others). `PinDriftHandler` (`lib/Service/Pin/PinDriftHandler.php:87-99`) shows the pattern: one notification per admin, once per event.
- Installing goes through `InstallerService::installAppVersion()` (`lib/Service/InstallerService.php:407`), which also serves apps bound to a source but not installed yet (`getInstalledApps()` state `notInstalled`, line 89). Disabling is the server's `IAppManager::disableApp()`.
- `inventory-pending-updates` specifies `AvailabilityService` and its snapshot; this change adds fields to the same sweep instead of a second pass over every source.

## Goals and non-goals

Goals: say which installed apps are abandoned or out of support, say when the server leaves support, and move an app to its successor in one action.

Non-goals: moving data between apps, updating the server.

## Decisions

### D1. Four signals, all from data Versioniq already fetches

| Signal | Source |
|---|---|
| `removedFromStore` | store-bound, not shipped, catalogue has no entry |
| `archived` | forge repository reports `archived: true` (one extra call per forge app per sweep) |
| `noReleaseFor12Months` | newest release date older than twelve months (App Store `created`, forge `published_at`) |
| `endOfSupport` | no listed release supports a server major that is still maintained (D2) |

A card shows the strongest signal as a badge ("Removed from the App Store", "Repository archived", "No release in a year", "End of support") with the others in its tooltip. Twelve months matches the Easy Updates Manager rule the matrix cites and is an app config key `lifecycle.stale_months`.

### D2. Server end of life from a feed

`ServerEolFeed` reads `https://endoflife.date/api/nextcloud.json`, which lists each Nextcloud major with its end-of-life date. The address is an instance setting (`lifecycle.eol_feed`, next to the advisory feed address in `InstanceSettings`), so an offline instance can point it at a mirror or leave it empty to switch it off. The answer is cached for a day. "Still maintained" means a major whose end-of-life date is in the future.

Alternative considered: the server's own update check (`VersionCheck`, eol flag). Rejected: it says "not maintained" only after the fact and gives no date, which is exactly the matrix's criticism of it.

### D3. Warnings before the date

`ServerEolWarningJob` (daily `TimedJob`) notifies every admin 90 and 30 days before the running major's end-of-life date, once per threshold, with subject `server_eol`. The Advisories tab shows the date in its Nextcloud server section (the `:server` section of `src/components/AdvisoriesPanel.vue`).

### D4. The successor list

`lib/Settings/successors.json` ships known entries (`{from, to, source}`, where `source` is a source id such as `appstore` or `github:ConductionNL/integriq`). An admin adds or removes entries from the Settings tab; they live in app config `successors.custom`. An entry is offered only when the successor's source lists at least one version this server can run, checked when the card is rendered from the snapshot. Entries are data, not code: Versioniq never rewrites an app id anywhere else.

### D5. The replace action

`POST /api/app/{appId}/replace` (admin, password-confirmed) takes the successor entry, installs the successor's newest compatible version through `installAppVersion()` with the entry's source (bound on success, as any install), and only after a successful real install disables the old app with `IAppManager::disableApp()`. Each step writes an audit row (`install` for the successor, a new `replace` operation for the old app). A failed install leaves the old app enabled and untouched. `ReplaceAppDialog.vue` states that the old app's data stays and that moving it is the successor's job.

## Risks and trade-offs

- [endoflife.date is a third party] → the address is a setting, an empty value switches the warnings off, and the card's `endOfSupport` then reads unknown instead of guessing.
- [A successor entry that is wrong disables a working app] → the action installs first and disables only after success, asks for the password, and is audited; the old app can be enabled again from its card.
- [One extra forge call per app for `archived`] → made in the same sweep, under its budget.

## Migration

No schema change. Rollback: revert; the app config keys are inert.
