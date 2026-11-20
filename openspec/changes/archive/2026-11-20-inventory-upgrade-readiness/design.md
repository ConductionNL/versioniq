# Design: inventory-upgrade-readiness

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AppStoreSource::normalizeVersions()` (`lib/Service/Source/AppStoreSource.php:586-640`) reads `platformVersionSpec` per release and turns it into `serverCompatible` through `satisfiesPlatformSpec()` (line 663). It ignores `phpVersionSpec`, which the App Store API returns on the same release object.
- `ForgeReleaseSource::listVersions()` (`lib/Service/Source/ForgeReleaseSource.php:91-128`) lists tags and release bodies only. Forges publish no range in release metadata.
- `InstallerService::applyCachedServerCompatibility()` (`lib/Service/InstallerService.php:334-352`) fills `serverCompatible` for a forge release only when `ArtifactCache::platformSpecFor()` (`lib/Service/Cache/ArtifactCache.php:204-239`) can read `info.xml` out of a cached archive.
- The PHP requirement is enforced only inside a real install, through the server's `\OC_App::checkAppDependencies` (`lib/Service/SelectedReleaseInstallerService.php:346`, `lib/Service/ExternalReleaseInstallerService.php:602`), which reverts after the file swap.
- `Forge` (`lib/Service/Source/Forge.php:52-70`) builds the releases and advisories endpoints from the forge's API base; GitHub and Forgejo both serve a file at a ref (`/repos/{owner}/{repo}/contents/{path}?ref={tag}`).

## Goals and non-goals

Goals: a range for every listed release where one can be read, and one report that answers "what breaks if I move to server N or PHP X".

Non-goals: upgrading the server, installing from the report, PHP extensions and databases.

## Decisions

### D1. The App Store PHP range travels with the version

`normalizeVersions()` also reads `phpVersionSpec` and adds `phpSpec` (the raw range) and `phpCompatible` (the running `PHP_VERSION` checked against it, null when unreadable) to each entry, next to `serverCompatible`. `platformSpec` is kept raw as well, so the readiness check can test a target other than the running server. `satisfiesPlatformSpec()` already handles the range syntax; a sibling `satisfiesPhpSpec()` uses the same bound rules.

Alternative considered: a second App Store request per check. Rejected: the catalogue is already fetched and cached for every app in one download (`cacheCatalogueEntries`, line 490).

### D2. A forge range is read once per release tag

`ForgeRangeStore` keeps `{nextcloudMin, nextcloudMax, phpMin, phpMax}` per `sourceId` and version in app config (`forge_range.<sourceId>.<version>`). When `getAppVersions()` finds a forge version with no stored range and no cached archive, it asks the forge for `appinfo/info.xml` at the tag through the same authenticated fetch `ForgeReleaseSource` uses for releases (`performFetch`, line 307), for at most the five newest versions per request, and stores what it read. An unreadable file stores an explicit "no range", so it is not asked again.

Alternative considered: download the release archive. Rejected: an archive is megabytes, `info.xml` is a few kilobytes.

### D3. The readiness report

`UpgradeReadinessService::check(string $serverMajor, string $phpVersion)` walks the apps `getInstalledApps()` returns and gives each one verdict:

| Verdict | When |
|---|---|
| `shipped` | `isShipped` is true; the app follows the server release |
| `ready` | the installed version's ranges admit both targets |
| `updateFirst` | the installed version does not, and a newer listed version does; the report names the lowest such version |
| `blocked` | no listed version admits both targets |
| `unknown` | the source gives no range for the versions that matter |

The ranges for the installed version come from its own `info.xml` through `IAppManager::getAppInfo()`, so the verdict for "what runs now" never depends on a source answering.

### D4. Where it runs

The web check queues `UpgradeReadinessJob` (a `QueuedJob` carrying the two targets) and returns at once; the job stores the report in `readiness.results` with its time and targets, on the `AdvisoryResultStore` pattern. `GET /api/readiness` reads it; `POST /api/readiness` (admin, password-confirmed like other writes) queues a check. This keeps the page clear of the issue #160 failure, where a request that listed every app did not return. `occ versioniq:readiness` runs the service directly, because the CLI has the time.

### D5. The page

A "Upgrade readiness" tab (`src/components/ReadinessPanel.vue`, added to `tabs` in `src/App.vue:226`) has two fields, the target server major (default: the running major plus one) and the target PHP version (default: the running PHP), a Check button, the time of the last report, and a table grouped by verdict. The version picker's `ServerCompatBadge` gains a PHP line for versions that carry `phpCompatible`.

## Risks and trade-offs

- [A forge repository without `appinfo/info.xml` at the tag root] → stored as "no range" and reported `unknown`, never `blocked`.
- [Five extra forge calls per version list] → only for versions with no stored range, so a list costs them once per new release.
- [A publisher declares a range that is wrong] → the report says what the publisher declared, and the install still runs the server's own dependency check.

## Migration

No schema change. The queued job is created on demand. Rollback: revert; the app config keys are inert.
