# Design: advisories-ncsc-feed

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AdvisoryService::correlateAll()` (`lib/Service/Advisory/AdvisoryService.php:159`) reads the Nextcloud feed once per sweep (`:166-172`), correlates every enabled app with the records keyed to it (`:205`), and adds the `:server` row from records keyed to the server (`:218-225`). A record reaches `evaluate()` (`:245`) either with `patchedVersions` (branch-aware) or with `affected` clauses (`:269-285`).
- `AdvisorySourceInterface` (`lib/Service/Advisory/AdvisorySourceInterface.php:32`) is per app and per binding: `listAdvisories(string $appId, SourceBinding $binding)`. A central feed does not fit it, which is why `NextcloudAdvisoryFeed` (`lib/Service/Advisory/NextcloudAdvisoryFeed.php:39`) is a separate class whose `fetchAll()` (`:93`) returns records keyed by target and reports errors instead of throwing (`:108-123`, `:280-285`).
- `AdvisoryPackageMap::resolve()` (`lib/Service/Advisory/AdvisoryPackageMap.php:86`) turns a product name into an app id, `:server`, or null, matching normalised ids and display names (`:112-147`) and dropping clients (`:66`, `:99-101`).
- `AdvisorySeverity::normalize()` maps a raw severity onto `low`, `medium`, `high`, `critical` or `unknown` (`lib/Service/Advisory/AdvisorySeverity.php:29-38`).
- `AdvisoryRefreshJob` gives the sweep 600 s (`lib/BackgroundJob/AdvisoryRefreshJob.php:47`).
- `ConnectionReportService` reports feed reads to integriq (`lib/Service/Connection/ConnectionReportService.php:192`), with one key per connection (`:51-57`) and the rows declared in `lib/Settings/connections.json`.
- The NCSC-NL data, read on 2026-09-27: provider metadata at `https://advisories.ncsc.nl/.well-known/csaf/provider-metadata.json` (role `csaf_trusted_provider`, directory `https://advisories.ncsc.nl/csaf/v2/`); `changes.csv` lines such as `"2026/ncsc-2026-0389.json","2026-09-24T09:14:33.175708+00:00"`, newest first; document NCSC-2026-0389 with `document.tracking.id`, `document.title` in Dutch, `document.tracking.initial_release_date`, `document.notes` entries titled `Kans` and `Schade`, a `product_tree` of `vendor`, `product_name` and `product_version_range` branches (`vers:unknown/*` in that document), and `vulnerabilities[]` with `cve`, `product_status.known_affected` and `scores[].cvss_v3.baseSeverity`.

## Goals and non-goals

Goals: NCSC-NL advisories that name a Nextcloud product are matched to the server or an installed app, with a version check where the document allows one, shown with their own ids and ratings, and never double-counted with a Nextcloud advisory for the same CVE.

Non-goals: other vendors' products, other CERTs, bundled libraries.

## Decisions

### D1. A central feed beside the Nextcloud feed, not an advisory source

`NcscAdvisoryFeed::fetchAll(float $budgetSeconds): array{advisories: array<string, list<array>>, error: ?string}` has the shape of `NextcloudAdvisoryFeed::fetchAll()`: records keyed by app id or `:server`, errors returned, partial reads kept. `AdvisoryService::correlateAll()` calls it right after the Nextcloud feed when `advisory.ncsc_enabled` is true, and merges the two maps per target before correlating.

Alternative considered: implement `AdvisorySourceInterface`. Rejected: that interface answers per app and per binding, and NCSC-NL is neither.

### D2. An incremental read of the directory

- `advisory.ncsc.cursor` holds the newest `changes.csv` time already processed.
- Each sweep reads `changes.csv`, takes the lines newer than the cursor, oldest first, and fetches each document. On the first run it takes only lines from the last 365 days.
- A sweep fetches at most 300 documents and stops at its budget of 120 s. The cursor moves only past documents that were processed, so the rest are read in the next sweep.
- Documents that name no Nextcloud product are dropped after reading. Those that do go into `advisory.ncsc.index`, keyed by tracking id, replacing an older version of the same document.

The index, not the raw feed, is what the merge reads. So each sweep matches every indexed NCSC-NL advisory against the current installed versions, even if the document was read months ago.

Alternative considered: read every document on every sweep. Rejected: NCSC-NL publishes more than one advisory a day on average, about all kinds of products (it reached NCSC-2026-0393 by 24 September), so a full read is hundreds of requests per sweep for a handful of matches.

### D3. Product matching

`CsafProductMatcher::targets(array $document): list<array{target: string, range: ?string, productIds: list<string>}>` walks `product_tree.branches` recursively. It keeps branches under a `vendor` whose name normalises to `nextcloud` or `nextcloudgmbh`. For each `product_name` under it, it strips a leading "Nextcloud" from the name and resolves the rest through `AdvisoryPackageMap::resolve()`: "Nextcloud Server" becomes `server`, which is the server target; "Nextcloud Talk" becomes `Talk`, which the display-name index resolves to `spreed`. A name that resolves to nothing, or to a client, is dropped, as in the Nextcloud feed. A product counts only when a vulnerability lists it in `product_status.known_affected`.

### D4. Version check, and "not stated"

`VersRange::matches(string $vers, string $version): ?bool` evaluates the `vers:` form the documents use: comparators `<`, `<=`, `>`, `>=`, `=`, `!=` joined by `|`, with `*` for all versions. It returns null for `vers:unknown/...` or anything it cannot parse.

Each NCSC-NL record reaches `evaluate()` with a `match` of `affected`, `not_affected` or `version_not_stated`, worked out against the installed version during the sweep. `evaluate()` treats `affected` as an active advisory and the other two as history only. So a document that does not say which versions it covers never marks an installed version affected; the page shows it with the line "NCSC-NL does not state which versions are affected."

The document read on 2026-09-27 had no `remediations`, so a fixed version cannot be relied on. When an NCSC-NL record is the only active advisory for an app, the recommended version comes from other records, or stays empty, and the page says no fix is named.

### D5. One entry per CVE

Each record carries `cveIds`. When an NCSC-NL record and another record for the same target share a CVE id, the merge keeps the other record, adds the NCSC-NL id to its `relatedIds`, and drops the NCSC-NL record, so the advisory counts once. The `cveIds` field on Nextcloud feed records is added by `advisories-per-app-detail`; until it lands, this change reads `cve_id` from the GHSA record itself (`NextcloudAdvisoryFeed::targetsFor()`, `lib/Service/Advisory/NextcloudAdvisoryFeed.php:166`), which is the same field.

### D6. Severity and ratings

Severity is the highest `scores[].cvss_v3.baseSeverity` among the document's vulnerabilities that list the product, through `AdvisorySeverity::normalize()`, or `unknown` when none is scored. NCSC-NL's `Kans` and `Schade` notes are stored as given and shown next to it as "NCSC-NL likelihood: medium, damage: high". They are never turned into a severity, because NCSC-NL defines them on its own scale.

### D7. Setting, display and connection

- `advisory.ncsc_enabled`, default false, is a checkbox under "Security advisory checks" (`src/App.vue:2130-2171`) and a field of `GET` and `PUT /api/advisory/settings`.
- `advisory.ncsc_feed_base` overrides the directory URL, https only, from the Settings tab like `advisory.feed_base`, so the e2e fixture and an internal mirror can serve it.
- `AdvisoriesPanel.vue` shows NCSC-NL records with a "NCSC-NL" label, the tracking id, the Dutch title as published, the CVE ids, the severity, the two ratings, the release date and a link (the document's `self` reference when present, else the CSAF file URL). A merged record shows both ids.
- `connections.json` gains a reportedOnly row `ncsc` ("NCSC-NL security advisories"), and the reader reports each read through `ConnectionReportService`, like `advisoryFeedRead()`.

## Risks and trade-offs

- [NCSC-NL names a product in a way the map does not resolve] → the record is dropped and counted in the connection report as "read N documents, matched M", so a gap is visible, not silent.
- [A first read of 365 days takes several sweeps] → the Advisories tab says "NCSC-NL: catching up, read up to {date}" until the cursor reaches the present.
- [NCSC-NL changes the CSAF layout] → the reader follows the CSAF 2.0 standard fields only; a document it cannot parse is skipped and counted, never guessed.
- [The Dutch title shows to an English-speaking admin] → it is NCSC-NL's text and is shown as published, marked as NCSC-NL's.

## Migration

No schema change. The switch is off on every existing instance, so nothing is fetched until an admin turns it on. Rollback: revert; the four keys are inert.
