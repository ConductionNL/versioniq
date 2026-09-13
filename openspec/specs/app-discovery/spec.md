---
status: implemented
---

# App Discovery Specification

**Status**: implemented
**Standards**: Nextcloud App Store API v1, GitHub REST API v2022-11-28 (Search Code), WAI-ARIA 1.2 (tabs, combobox/listbox semantics), Nextcloud Vue components
**Feature tier**: MVP

## Purpose

A multi-source search aggregator that lets admins find Nextcloud apps to install across the App Store, their PAT-visible private GitHub repos, and (opt-in) public GitHub topic search — via a single API endpoint with a uniform result shape.

## Requirements

### Requirement: Provider interface [MVP]

The system MUST expose a single `DiscoveryProviderInterface` so additional sources (Software Catalogus, federated peers, etc.) can plug in without changes to the aggregator or the controller.

#### Scenario: Multiple providers registered

@e2e exclude provider registry wiring is unit-tested (DiscoveryAggregator).

- **GIVEN** the system has `AppStoreDiscovery`, `GithubPrivateDiscovery`, and `GithubSearchDiscovery` registered
- **WHEN** an admin calls `GET /api/discover?q=register`
- **THEN** the aggregator MUST iterate every enabled provider
- **AND** each provider MUST return a `DiscoveryResult` with `hits` and an optional `error`

### Requirement: App Store discovery [MVP]

The system MUST search the Nextcloud App Store catalog by case-insensitive substring match across name, summary, description, and categories.

#### Scenario: Match by name

@e2e tests/e2e/discovery.spec.ts

- **GIVEN** the App Store catalog contains an app with name "Open Register"
- **WHEN** an admin searches for `register`
- **THEN** the result MUST contain a hit with `appId = "openregister"` (or whatever the actual id is)
- **AND** `sourceBinding = {kind: "appstore"}`

#### Scenario: No match

@e2e tests/e2e/discovery.spec.ts

- **GIVEN** no App Store entry matches the query
- **WHEN** the admin searches
- **THEN** the App Store provider MUST return `hits = []` with no error

#### Scenario: Catalog cache hit

@e2e exclude the 1-hour catalog cache is unit-tested in AppStoreDiscovery.

- **GIVEN** the App Store catalog was fetched within the last hour
- **WHEN** a second search runs
- **THEN** the provider MUST NOT re-fetch the catalog
- **AND** the response time MUST be measurably faster than the first call

### Requirement: GitHub private discovery [MVP]

When the current admin has at least one PAT in `versioniq_pats`, the system MUST use it to search private GitHub repos for `appinfo/info.xml` matching the query.

#### Scenario: With PAT and matching private repo

@e2e exclude requires a real private GitHub repo + PAT; GithubPrivateDiscovery is unit-tested.

- **GIVEN** an admin has uploaded a PAT with `target_pattern = ConductionNL/*`
- **AND** the org has a private repo `ConductionNL/private-app` with `appinfo/info.xml` declaring `<id>privateapp</id>`
- **WHEN** the admin searches for `private`
- **THEN** the provider MUST return a hit with `appId = "privateapp"`
- **AND** `sourceProviderId = "github-private"`
- **AND** `sourceBinding = {kind: "github-release", owner: "ConductionNL", repo: "private-app"}`
- **AND** `installable = true` (matches the trusted-source allowlist)

#### Scenario: PAT covers a non-allowlisted repo

@e2e exclude the installable-flag-vs-allowlist logic is unit-tested.

- **GIVEN** an admin's PAT can see `OtherOrg/some-app` but `OtherOrg/*` is NOT in the trusted-source allowlist
- **WHEN** the search returns a hit for that repo
- **THEN** the hit MUST be returned with `installable = false`
- **AND** `installableReason` MUST explain the missing allowlist entry

#### Scenario: No PAT configured

@e2e exclude the private provider is disabled without a PAT; unit-tested.

- **GIVEN** the current admin has no visible PATs
- **WHEN** discovery runs
- **THEN** `GithubPrivateDiscovery::isEnabled()` MUST return false
- **AND** the provider MUST be excluded from the response's `providers` list with `enabled: false`

### Requirement: GitHub public search (opt-in) [MVP]

The system MUST provide an opt-in public GitHub code search provider that is disabled by default.

#### Scenario: Disabled by default

@e2e exclude the public-search opt-in flag is unit-tested.

- **GIVEN** `versioniq.discovery.github_search_enabled` has never been set
- **WHEN** the admin calls `GET /api/sources`
- **THEN** `github-search` MUST appear in the providers list with `enabled = false`

#### Scenario: Enabled returns public hits

@e2e exclude opt-in public GitHub code search needs real GitHub; unit-tested.

- **GIVEN** the admin runs `occ config:app:set versioniq discovery.github_search_enabled --value=true`
- **WHEN** they search for `register`
- **THEN** the provider MUST query `https://api.github.com/search/code?q=path:appinfo+filename:info.xml+register`
- **AND** return up to 30 hits annotated with allowlist status

#### Scenario: Rate limited

@e2e exclude provider rate-limit handling is unit-tested; the public providers cannot be rate-limited on demand in e2e.

- **GIVEN** GitHub returns 403 with `X-RateLimit-Remaining: 0`
- **WHEN** the search runs
- **THEN** the provider MUST NOT throw
- **AND** MUST return `hits = []` with `error = "GitHub search rate limit exceeded — try again at HH:MM"`

### Requirement: Result aggregation [MVP]

The system MUST de-duplicate hits by `appId` and present a single result per app with all candidate sources.

#### Scenario: Same app from multiple providers

@e2e exclude cross-provider dedupe is unit-tested; only one provider returns the fixture in e2e.

- **GIVEN** App Store has `openregister` AND a PAT-visible repo also has `appinfo/info.xml` declaring id `openregister`
- **WHEN** the admin searches for `register`
- **THEN** the response MUST contain ONE result row for `openregister`
- **AND** `sourceCandidates` MUST list both: `{providerId: "appstore", ...}` and `{providerId: "github-private", ...}`

#### Scenario: Already installed apps annotated

@e2e tests/e2e/discovery.spec.ts

- **GIVEN** `openregister` is currently installed at version `0.2.13`
- **WHEN** the admin searches for `register`
- **THEN** the result row MUST include `installedVersion = "0.2.13"`

#### Scenario: installedOnly filter

@e2e tests/e2e/discovery.spec.ts

- **GIVEN** the admin calls `GET /api/discover?q=register&installedOnly=true`
- **WHEN** the response builds
- **THEN** only results with a non-null `installedVersion` MUST appear

### Requirement: Discovery API [MVP]

The system MUST expose `GET /api/discover` with admin-only access and consistent error handling.

#### Scenario: Query too short

@e2e tests/e2e/discovery.spec.ts

- **GIVEN** an admin calls `GET /api/discover?q=a`
- **WHEN** the request is processed
- **THEN** the system MUST return HTTP 400
- **AND** the message MUST explain the minimum query length (2)

#### Scenario: Source filter

@e2e exclude the source-filter NcSelect exposes no stable test hook; provider selection is unit-tested.

- **GIVEN** an admin calls `GET /api/discover?q=register&sources=appstore`
- **WHEN** discovery runs
- **THEN** only `AppStoreDiscovery` MUST run
- **AND** the response's `providers` list MUST still report all providers' enabled state

#### Scenario: Non-admin blocked

@e2e tests/e2e/shell.spec.ts

- **GIVEN** a non-admin user calls the endpoint
- **THEN** the response MUST be 403 Forbidden

### Requirement: Discover tab surfaces multi-source search [MVP]

The admin SPA MUST provide a Discover tab containing a debounced search input (client-enforced 2–100 characters, mirroring the API), an optional source filter, and an installed-only toggle, calling `GET /api/discover` and rendering the aggregated hits. Each hit MUST show name, appId, source badges from `sourceCandidates`, and the installed version when present. Loading, empty-result, and request-error states MUST be distinct and localized. Per-provider partial failures reported by the aggregator MUST render as dismissible notes without suppressing the remaining results.

#### Scenario: Search renders multi-source hits

@e2e tests/e2e/discovery.spec.ts

- GIVEN an admin on the Discover tab with a PAT configured
- WHEN they type "openregister" (debounced)
- THEN hits from the App Store and GitHub private providers MUST render with source badges
- AND an installed app's hit MUST show its installed version

#### Scenario: Partial provider failure stays usable

@e2e exclude cannot force one provider to fail while another succeeds in e2e; the aggregator fail-soft path is unit-tested.

- GIVEN the GitHub provider errors (rate limit) while the App Store provider succeeds
- WHEN the search completes
- THEN App Store hits MUST render and a dismissible note MUST name the failing provider

#### Scenario: Input validation mirrors the API

@e2e tests/e2e/discovery.spec.ts

- WHEN the admin types a single character
- THEN no request MUST be sent and a hint MUST indicate the minimum length

### Requirement: Hits route into existing flows [MVP]

From a hit, the admin MUST be able to: (a) for an installed app, jump to the Apps tab with that app's version picker opened; (b) for a not-installed app with an installable source candidate, jump to the Sources flow prefilled with that candidate; (c) for a non-installable hit, see the reason (source not in the trusted allowlist) and a link to the Trusted sources tab. Discovery MUST NOT introduce any install path that bypasses binding, allowlist validation, or password confirmation.

#### Scenario: Installed hit opens the picker

@e2e tests/e2e/discovery.spec.ts

- GIVEN `openregister` is installed
- WHEN the admin activates its Discover hit
- THEN the Apps tab MUST open with the `openregister` version picker expanded

#### Scenario: Installable candidate prefills bind

@e2e exclude requires a not-installed, allowlisted app to appear in App Store/GitHub discovery; the fixture app is not published there.

- GIVEN a not-installed private app hit with candidate `github:ConductionNL/hermiq` and that pattern allowlisted
- WHEN the admin activates the hit's install action
- THEN the Sources bind flow MUST open prefilled with `github:ConductionNL/hermiq`

#### Scenario: Non-installable explains why

@e2e exclude requires a non-installable discovery hit from a real provider; the reason rendering is covered by DiscoverPanel vitest.

- GIVEN a hit whose only candidate is not allowlisted
- WHEN it renders
- THEN the card MUST state the source is not trusted and link to the Trusted sources tab

## User Stories

1. As an admin, I want to search across the App Store and my private repos in one go so I don't have to remember where each app lives.
2. As an admin who just uploaded a PAT, I want my private repos to immediately appear in search results without any further setup.
3. As an admin curious about apps not yet in the App Store, I want to opt into a public GitHub search so I can discover community apps.
4. As an admin, I want already-installed apps to surface in search with their version so I can manage versions from the same UI.

## Acceptance Criteria

- [ ] `GET /api/discover?q=register` returns App Store hits in dev env
- [ ] `GET /api/discover?q=register&sources=appstore` filters to App Store only
- [ ] `GET /api/discover?q=` (or query <2 chars) → 400
- [ ] Catalog caching avoids re-fetching the App Store within a 1-hour window
- [ ] `GithubSearchDiscovery` is disabled by default; flips on via `occ config:app:set`
- [ ] Already-installed apps surface with `installedVersion`
- [ ] De-duplication by `appId` produces one row per app even when multiple providers match

## Notes

- The frontend search bar + result cards UI (`src/components/DiscoverPanel.vue`, the Discover tab in `App.vue`) shipped in `add-discovery-search-ui`; see "Discover tab surfaces multi-source search" and "Hits route into existing flows" above.
- Federation (asking another Nextcloud's Versioniq for its search results) is future work.
- Software Catalogus integration is tracked in issue #24 (Codeberg numbering, pre-migration; the same request is tracked on GitHub as ConductionNL/versioniq#24).
