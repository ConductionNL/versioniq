# Design: advisories-per-app-detail

Read against `development` at 02e1050 (2026-09-27).

## Context

- `NextcloudAdvisoryFeed::targetsFor()` (`lib/Service/Advisory/NextcloudAdvisoryFeed.php:166`) reads `ghsa_id`, `severity`, `summary` and `vulnerabilities` from each GitHub repository security advisory (`:133`, `:173-174`, `:168`) and builds records of id, severity, summary, affected, first patched and patched versions (`:205-215`). The GitHub object also carries `cve_id`, `identifiers`, `published_at` and `html_url`; they are dropped here.
- `ForgeReleaseSource::normalizeAdvisory()` (`lib/Service/Source/ForgeReleaseSource.php:218`) takes the id from `ghsa_id`, `id` or `cve_id` (`:220`) and keeps no date or link.
- `AdvisoryService::evaluate()` (`lib/Service/Advisory/AdvisoryService.php:245`) returns only the active advisories for an affected version (`:308-315`) and all advisories for an unaffected one (`:287-298`), both through `summarise()` (`:343`), which keeps id, severity and summary. The recommended version is the lowest branch patch or the nearest resolving version (`:304-306`); `nearestResolving()` returns null when nothing clears the advisories (`:428-460`).
- `correlate()` lists versions from the bound source (`:134-137`) but keeps only the version strings. `AppStoreSource` returns `serverCompatible` per version from `platformVersionSpec` (`lib/Service/Source/AppStoreSource.php:575-639`); `ForgeReleaseSource` does not.
- `AdvisoriesPanel.vue` shows the server block (`src/components/AdvisoriesPanel.vue:32-64`) and each app row (`:66-100`) in the order `splitAdvisoryRows()` returns them. The card badge shows the first advisory's summary as its tooltip (`src/App.vue:2233-2237`) and, for an affected version, the first advisory id (`:2254-2256`).
- The freshness line on the Apps tab (`src/App.vue:2039-2043`) comes from `advisoryFreshnessLabel()` (`src/utils/advisoryFreshness.ts`), which says how old the check is but has no notion of too old.
- `App.vue` already opens a tab from a `#section-…` hash (`openSectionFromHash()`, `src/App.vue:268`), through `tabForHash()` (`src/utils/connectionRegistry.ts:126`).
- `GET /api/advisories` returns the snapshot and `checkedAt` (`lib/Controller/ApiController.php:138-149`). The CLI has `versioniq:versions` and `versioniq:install` (`appinfo/info.xml` `<commands>`).

## Goals and non-goals

Goals: per product, the recent advisories with CVE ids and dates, newest first; a stated reason whenever an affected version has no installable fix; one compliance status for the instance, on the page and for scripts.

Non-goals: dismissals (see `advisories-triage`), deadlines (see `audit-security-reports`), library advisories (see `advisories-bundled-libraries`).

## Decisions

### D1. Richer advisory records

Every advisory record gains `cveIds` (list; from `cve_id` and any `identifiers` entry of type `CVE`), `publishedAt` (ISO string or null) and `url` (the advisory's web page or null). `NextcloudAdvisoryFeed`, `ForgeReleaseSource` and `AppStoreSource` fill them when the source has them, and leave them empty otherwise. `summarise()` keeps them.

`evaluate()` adds a `history` list to every row: all advisories for the target, each with an `affectsInstalled` flag, sorted by `publishedAt` descending and then by id. `advisories` keeps its current meaning, so the notifier, digest and badge read what they read today.

Alternative considered: replace `advisories` with `history`. Rejected: three readers depend on `advisories` holding the active ones for an affected version, and changing that is a change to the notification contract.

### D2. Why there is no installable fix

For a row in state `pinned-to-vulnerable`, the sweep sets `fixStatus` from the version list `correlate()` already fetched, now kept with its `serverCompatible` values:

| `fixStatus` | When |
|---|---|
| `installable` | the source lists `recommendedVersion` and it is not marked incompatible |
| `no_fix_published` | `recommendedVersion` is null: no advisory names a patched version and no listed version clears them |
| `not_in_source` | a fix is named, but the bound source does not list it |
| `needs_newer_server` | the source lists the fix, but marks it and every later clearing version incompatible with this server |

The server row gets `no_fix_published` or `installable` only; its fix is a server upgrade, which Versioniq does not install.

`FixStatus::for(array $row, array $versions): string` is a pure function. At read time the controller adds `heldByPin: true` when the app has a pin below an installable fix, from `PinStore`, because a pin can change between sweeps. The Advisories tab turns each status into one sentence, for example "No fixed version is published yet." or "The fix needs a newer Nextcloud than this server runs."

### D3. The list on the page

`AdvisoriesPanel.vue` renders each block from `history`: the five most recent, then a "Show all {n}" toggle. Each entry shows the CVE ids (or "No CVE id"), the GHSA or source id, the date (or "Date unknown"), the severity, "Affects the installed version" or "Fixed in the installed version", and links the advisory `url`. The affected state line carries the `fixStatus` sentence. Each app block gets the anchor `section-advisories-{appId}`, and `tabForHash()` learns to resolve that prefix to the Advisories tab. The card badge becomes a link to that anchor, with the tooltip "Show the advisories for this app".

### D4. One compliance status

`ComplianceStatus::from(array $snapshot, ?int $checkedAt, int $intervalHours, int $now): array` returns:

- `unknown` when `checkedAt` is null or older than twice the interval, with `reason` `never_checked` or `stale`;
- `not_compliant` when any app row or the server row is `pinned-to-vulnerable`, with the count of affected targets per highest severity and their ids;
- `unknown` with `reason` `unreached` when no row is affected but some rows carry an `error`, with the count;
- `compliant` otherwise.

`GET /api/advisories` adds `compliance`. The Apps tab shows it in the line above the freshness line, and the Advisories tab at the top, for example "Not compliant: 2 apps run an affected version (1 critical, 1 medium)". Once `advisories-triage` lands, the status reads the rows after dismissals are applied.

Alternative considered: compute the status in the browser. Rejected: the command needs the same answer, and one server-side function keeps the page and the script from disagreeing.

### D5. The command

`lib/Command/ListAdvisories.php`, `occ versioniq:advisories`, registered in `appinfo/info.xml`. It reads the stored snapshot, prints the status line and per target the affected and recent advisories as a table, or everything as JSON with `--json`. `--app <id>` limits the output to one target (`:server` for the server). `--check` sets the exit code from the status: 0 compliant, 1 not compliant, 2 unknown. Without `--check` it exits 0 whenever it printed. It never calls a source.

## Risks and trade-offs

- [Older snapshots lack the new fields] → the page shows "Date unknown" and "No CVE id" until the next sweep, and the first sweep after the upgrade fills them.
- [A server upgrade looks like a fix Versioniq could make] → the server row never gets `not_in_source` or `needs_newer_server`, and its sentence says to upgrade Nextcloud.
- [`unknown` because one forge rate-limited a single app reads as a failure of the whole instance] → the status names how many apps could not be checked, and those rows carry their error.

## Migration

No schema change. The snapshot grows by the new fields on the next sweep; nothing is migrated. Rollback: revert; older code ignores the extra fields.
