---
status: implemented
---

# External Sources Specification

**Status**: proposed
**Standards**: GitHub REST API v2022-11-28 (releases endpoint), Nextcloud OCP\App\IAppManager
**Feature tier**: MVP

## Purpose

External sources allow Versioniq to install Nextcloud apps from origins outside the Nextcloud App Store — most importantly GitHub releases — while keeping the App Store install path with its full code-signing chain unchanged. The trade-off (no Nextcloud-issued certificate) is made visible through a trusted-source allowlist, archive-content integrity checks, and clear UI labelling.
## Requirements
### Requirement: Source abstraction [MVP]

The system MUST expose every install origin (App Store, GitHub releases, future others) through a single `SourceInterface` so the version picker and installer are not hard-coded to one source.

#### Scenario: Multiple sources registered

@e2e exclude source-registry wiring is unit-tested (SourceRegistry).

- **GIVEN** the app has a `SourceRegistry` with `appstore` and `github` sources registered
- **WHEN** an admin opens the version picker for an app
- **THEN** the registry MUST be able to list versions from any registered source by id
- **AND** swapping the source MUST NOT require changes to the controller layer

### Requirement: GitHub releases as a source [MVP]

The system MUST support querying public releases from any registered forge through a single generic driver (`ForgeReleaseSource`). For a source id of the form `{forge}:{owner}/{repo}`, the system queries `GET {forge.apiBaseUrl}/repos/{owner}/{repo}/releases` and normalizes the response into the same shape as App Store responses. For `forge = github` the behaviour MUST be identical to the prior GitHub-only driver (`https://api.github.com/...`, `Authorization: Bearer`). The installer kind MUST remain `INSTALLER_EXTERNAL`.

#### Scenario: Public releases available

@e2e tests/e2e/forge.spec.ts

- **GIVEN** an admin has bound app `openregister` to source `github:ConductionNL/openregister`
- **WHEN** the version picker loads
- **THEN** the system MUST fetch releases from `https://api.github.com/repos/ConductionNL/openregister/releases`
- **AND** version strings MUST be derived from the release `tag_name` (stripping a leading `v` if present)
- **AND** the response MUST be sorted newest-first

#### Scenario: GitHub API rate-limited

@e2e tests/e2e/forge.spec.ts

- **GIVEN** the GitHub API responds with status 403 and `X-RateLimit-Remaining: 0`
- **WHEN** the system tries to list versions
- **THEN** the system MUST log the rate-limit reset time
- **AND** the API response to the frontend MUST include a clear message ("GitHub rate limit exceeded — try again later, or configure a PAT")
- **AND** the system MUST NOT crash or expose stack traces

#### Scenario: Repository not found

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** the forge API responds with 404 for the repo
- **WHEN** the version picker loads
- **THEN** the system MUST return an empty version list with a "Repository not found" message

#### Scenario: GitHub behaviour preserved under generic driver

@e2e exclude the GitHub path through ForgeReleaseSource is unit-tested; e2e drives the codeberg path.

- **GIVEN** the driver is now `ForgeReleaseSource` parameterized by the `github` forge
- **WHEN** a `github:owner/repo` binding lists versions
- **THEN** the request URL, headers (`Authorization: Bearer`, `X-GitHub-Api-Version`), parsing, dedupe and sort MUST be unchanged from the prior GitHub-only behaviour

### Requirement: Trusted-source allowlist [MVP]

The system MUST reject external installs from sources not in the configured allowlist. Allowlist patterns are **forge-qualified** globs of the form `{forge}:owner/repo` (e.g. `github:ConductionNL/*`, `codeberg:Conduction/*`), stored in `versioniq.trusted_sources` as a JSON array. Matching is performed against the binding's `{forge}:owner/repo` id. A stored pattern with **no** forge prefix (legacy bare `owner/repo`) MUST be treated as `github:owner/repo`. When unset, the default is `["github:ConductionNL/*", "codeberg:Conduction/*"]`. The existing fnmatch and owner/repo path-traversal protections MUST be preserved.

#### Scenario: Forge-qualified source in allowlist

@e2e exclude TrustedSourceList glob matching is unit-tested.

- **GIVEN** trusted_sources is `["github:ConductionNL/*", "codeberg:Conduction/*"]`
- **WHEN** an admin tries to install from `github:ConductionNL/openregister`
- **THEN** `TrustedSourceList::assertAllowed` MUST succeed
- **WHEN** an admin tries to install from `codeberg:Conduction/openregister`
- **THEN** `TrustedSourceList::assertAllowed` MUST succeed

#### Scenario: Untrusted Codeberg source rejected

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** trusted_sources is `["github:ConductionNL/*", "codeberg:Conduction/*"]`
- **WHEN** an admin tries to install from `codeberg:randomuser/randomapp`
- **THEN** `TrustedSourceList::assertAllowed` MUST throw `UntrustedSourceException`
- **AND** the system MUST return HTTP 403 with a message naming the rejected source
- **AND** no download or filesystem change MUST happen

#### Scenario: Legacy bare pattern treated as github

@e2e exclude the legacy-pattern normalisation is unit-tested.

- **GIVEN** trusted_sources is the legacy bare `["ConductionNL/*"]`
- **WHEN** an admin tries to install from `github:ConductionNL/openregister`
- **THEN** the pattern MUST be interpreted as `github:ConductionNL/*` and the source MUST be allowed
- **AND** `codeberg:ConductionNL/openregister` MUST NOT match the bare github-only pattern

#### Scenario: Cross-forge isolation

@e2e exclude cross-forge allowlist isolation is unit-tested in TrustedSourceList.

- **GIVEN** trusted_sources is `["github:Conduction/*"]`
- **WHEN** an admin tries to install from `codeberg:Conduction/openregister`
- **THEN** `TrustedSourceList::assertAllowed` MUST throw `UntrustedSourceException` (a github pattern does not authorize a codeberg source)

#### Scenario: Unset allowlist falls back to default

@e2e exclude the default-allowlist fallback is unit-tested.

- **GIVEN** `versioniq.trusted_sources` has never been set
- **WHEN** the system reads the allowlist
- **THEN** the default `["github:ConductionNL/*", "codeberg:Conduction/*"]` MUST be used

### Requirement: External install integrity checks [MVP]

The system MUST verify the integrity of an externally-sourced artifact before install through non-cryptographic content checks plus an optional cryptographic checksum.

#### Scenario: appId match enforced

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** the admin requests install of `openregister` from a GitHub release
- **WHEN** the downloaded archive is extracted and contains `appinfo/info.xml` declaring `<id>otherapp</id>`
- **THEN** the install MUST fail with a clear message ("Downloaded archive declares appId 'otherapp', expected 'openregister'")
- **AND** the existing app MUST be untouched (no backup performed if appId check fails before backup, or backup restored if it failed after)

#### Scenario: Version match enforced

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** the admin requests install of `openregister@2.5.0` from GitHub release `v2.5.0`
- **WHEN** the extracted `appinfo/info.xml` declares `<version>2.4.0</version>`
- **THEN** the install MUST fail with "Downloaded archive declares version '2.4.0', expected '2.5.0'"

#### Scenario: SHA-256 verification when provided

@e2e tests/e2e/forge.spec.ts

- **GIVEN** the GitHub release has both `openregister-2.5.0.tar.gz` and `openregister-2.5.0.tar.gz.sha256` assets
- **WHEN** the system downloads the archive
- **THEN** the system MUST also fetch the `.sha256` file
- **AND** compare against the SHA-256 of the downloaded archive
- **AND** fail the install if the hashes do not match

#### Scenario: Missing SHA-256 produces warning, not failure

@e2e tests/e2e/forge.spec.ts

- **GIVEN** the GitHub release has only `openregister-2.5.0.tar.gz` (no `.sha256` sibling)
- **WHEN** the install proceeds
- **THEN** the install MUST succeed if other checks pass
- **AND** the install response payload MUST include `integrityWarning: "No SHA-256 checksum available for this artifact."`

#### Scenario: Asset selection unambiguous

@e2e exclude the single-unambiguous-asset rule is unit-tested in ForgeReleaseSource.

- **GIVEN** a GitHub release exposes two `.tar.gz` assets ("openregister-2.5.0.tar.gz" and "openregister-2.5.0-debug.tar.gz")
- **WHEN** the install runs without a configured `assetPattern`
- **THEN** the install MUST fail with "Multiple matching assets, set explicit assetPattern"
- **AND** no download MUST happen

### Requirement: SHA-256 recorded on first successful external install [Hardening]

On every successful external install, the system MUST record the artifact's SHA-256 in the app's source binding (`source.{appId}` payload, `sha256` map keyed by version). The digest MUST be taken from the verified `.sha256` sibling when one was checked, and otherwise computed locally from the downloaded archive. Recording MUST happen only after the install fully succeeded. The map MUST be capped (200 entries, oldest evicted first).

#### Scenario: Digest recorded from verified sibling

@e2e tests/e2e/forge.spec.ts

- GIVEN `openregister@2.5.0` is installed from `github:ConductionNL/openregister` and the release publishes a matching `.sha256` sibling
- WHEN the install succeeds
- THEN the binding for `openregister` MUST contain `sha256["2.5.0"]` equal to the verified digest

#### Scenario: Digest computed and recorded without sibling

@e2e tests/e2e/external-sources.spec.ts

- GIVEN the release for `openregister@2.4.0` has no `.sha256` sibling asset
- WHEN the install succeeds (with the existing `integrityWarning`)
- THEN the binding MUST contain `sha256["2.4.0"]` equal to the locally computed SHA-256 of the downloaded archive

#### Scenario: Failed install records nothing

@e2e tests/e2e/external-sources.spec.ts

- GIVEN an external install of `openregister@2.5.0` fails after download (e.g. appId mismatch in `appinfo/info.xml`)
- WHEN the install aborts
- THEN no `sha256["2.5.0"]` entry MUST be written for that attempt

### Requirement: Recorded SHA-256 enforced on reinstall [Hardening]

When the binding records a SHA-256 for the requested version, the system MUST compare it against the SHA-256 of the freshly downloaded artifact before extraction. On mismatch the install MUST fail with a message naming both digests and the machine-readable error code `sha_mismatch`, and no extraction, backup, or filesystem change MUST happen. The request parameter `acceptNewSha: true` MUST bypass the comparison for that single request and, on install success, replace the recorded digest; the replacement MUST be logged at warning level and audited when the audit-trail capability is available.

#### Scenario: Matching digest proceeds

@e2e tests/e2e/forge.spec.ts

- GIVEN the binding records `sha256["2.3.0"]` for `openregister`
- WHEN the admin rolls back to 2.3.0 and the downloaded artifact hashes to the recorded digest
- THEN the install MUST proceed through the existing checks
- AND the install response MUST indicate the artifact matched the first-install checksum

#### Scenario: Rewritten release fails closed

@e2e tests/e2e/forge.spec.ts

- GIVEN the binding records `sha256["2.3.0"]` and the upstream release asset has since been replaced with different bytes
- WHEN the admin attempts to reinstall 2.3.0 without `acceptNewSha`
- THEN the install MUST fail with error code `sha_mismatch` naming the expected and actual digests
- AND no extraction, backup, or change to the installed app MUST happen
- AND a co-published rewritten `.sha256` sibling MUST NOT cause the check to pass (the recorded digest takes precedence)

#### Scenario: Explicit acceptance replaces the recorded digest

@e2e tests/e2e/forge.spec.ts

- GIVEN a `sha_mismatch` failure for `openregister@2.3.0`
- WHEN the admin retries with `acceptNewSha: true` (password-confirmed install) and the install succeeds
- THEN `sha256["2.3.0"]` MUST be replaced with the new digest
- AND the replacement MUST be logged at warning level with both digests
- AND an audit entry MUST record the acceptance when the audit-trail capability is deployed

#### Scenario: acceptNewSha without a recorded digest is harmless

@e2e tests/e2e/external-sources.spec.ts

- GIVEN no digest is recorded for the requested version
- WHEN the admin installs with `acceptNewSha: true`
- THEN the install MUST behave exactly as a normal first install (record on success)

### Requirement: Recorded digests are binding-scoped and surfaced [Hardening]

Recorded digests MUST live inside the source binding payload so their lifecycle follows the binding: rebinding an app to a different source MUST discard the previous binding's digests, while rebinding to the same source MUST preserve them. The binding read API and the external version list MUST expose recorded digests (they are not secrets), and the version picker MUST badge versions that have a recorded digest.

#### Scenario: Rebinding to a different source discards digests

@e2e tests/e2e/external-sources.spec.ts

- GIVEN `openregister` is bound to `github:ConductionNL/openregister` with recorded digests
- WHEN the admin rebinds it to `github:myorg/openregister-fork`
- THEN the new binding MUST contain no `sha256` entries from the previous binding

#### Scenario: Digests visible in version list

@e2e tests/e2e/external-sources.spec.ts

- GIVEN the binding records `sha256["2.3.0"]`
- WHEN the admin loads the version list for `openregister`
- THEN the 2.3.0 entry MUST include the recorded digest
- AND the picker MUST badge 2.3.0 as having a first-install checksum on record

### Requirement: Source management API [MVP]

The system MUST provide HTTP endpoints for listing registered sources and the trusted-source allowlist, binding a source to an app, and **curating the trusted-source allowlist** (adding and removing forge-qualified patterns). Allowlist write operations MUST be admin-only and password-confirmed and MUST reject over-broad patterns.

#### Scenario: List sources

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** an admin calls `GET /api/sources`
- **THEN** the response MUST contain the registered source ids (including both github and codeberg forges) and the trusted-source patterns
- **AND** the response MUST NOT contain any secrets

#### Scenario: Bind a source

@e2e tests/e2e/version-management.spec.ts

- **GIVEN** an admin calls `POST /api/source/openregister/bind` with body `{forge: "github", kind: "github-release", owner: "ConductionNL", repo: "openregister"}`
- **THEN** the system MUST validate the source against the trusted-source allowlist
- **AND** persist the binding in `versioniq.source.openregister`
- **AND** future version queries for `openregister` MUST go to that forge source

#### Scenario: Bind rejects untrusted source

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** an admin calls `POST /api/source/foo/bind` with `owner: "untrusted"`
- **THEN** the system MUST return HTTP 403
- **AND** the binding MUST NOT be written

#### Scenario: Allowlist write delegates to TrustedSourceList

@e2e exclude the controller→TrustedSourceList delegation is unit-tested.

- **GIVEN** an admin curates the allowlist via `POST /api/trusted-sources` or `DELETE /api/trusted-sources?pattern=…`
- **THEN** the system MUST persist the change through `TrustedSourceList::setPatterns()`
- **AND** the change MUST survive a Nextcloud restart (config-backed via `IAppConfig`)

### Requirement: Forge abstraction [MVP]

The system MUST model each supported release forge as a `Forge` configuration entry carrying `id`, `apiBaseUrl`, `webBaseUrl`, `authScheme` (`Bearer` or `token`), `exposesScopeHeader` (bool), and `tokenCreateUrl`, exposed through a `ForgeRegistry`. The generic release driver and the token validator MUST read forge behaviour from this configuration rather than hard-coding GitHub.

#### Scenario: Known forges registered

@e2e exclude ForgeRegistry contents are unit-tested.

- **GIVEN** the `ForgeRegistry`
- **WHEN** `get('github')` and `get('codeberg')` are called
- **THEN** `github` MUST resolve to apiBaseUrl `https://api.github.com`, authScheme `Bearer`, exposesScopeHeader `true`, tokenCreateUrl `https://github.com/settings/tokens`
- **AND** `codeberg` MUST resolve to apiBaseUrl `https://codeberg.org/api/v1`, authScheme `token`, exposesScopeHeader `false`, tokenCreateUrl `https://codeberg.org/user/settings/applications`

#### Scenario: Unknown forge rejected

@e2e exclude ForgeRegistry rejects unknown forges — unit-tested.

- **GIVEN** the `ForgeRegistry`
- **WHEN** `get('gitlab')` is called
- **THEN** the registry MUST throw, so unknown forges cannot reach an outbound call

### Requirement: Codeberg releases as a source [MVP]

The system MUST support querying public Codeberg (Forgejo) releases as a source. For a source id of the form `codeberg:{owner}/{repo}`, the system queries `GET https://codeberg.org/api/v1/repos/{owner}/{repo}/releases` and normalizes the Forgejo response (`tag_name`, `assets[].name`, `assets[].browser_download_url`) into the same shape as GitHub and App Store responses.

#### Scenario: Public Codeberg releases listed via Forgejo API

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** an admin has bound app `openregister` to source `codeberg:Conduction/openregister`
- **WHEN** the version picker loads
- **THEN** the system MUST fetch releases from `https://codeberg.org/api/v1/repos/Conduction/openregister/releases`
- **AND** version strings MUST be derived from the release `tag_name` (stripping a leading `v` if present)
- **AND** the response MUST be sorted newest-first
- **AND** for a public repo the request MUST succeed unauthenticated

#### Scenario: Codeberg release resolved to a download asset

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** a Codeberg release `v2.5.0` with a single `*.tar.gz` asset
- **WHEN** the install resolves the release
- **THEN** the system MUST return the asset's `browser_download_url`, asset name, optional `.sha256` sibling URL, and normalized version
- **AND** asset selection MUST enforce the same unambiguous-match rule as GitHub (fail on multiple matches with "set explicit assetPattern")

#### Scenario: Codeberg auth header uses token scheme

@e2e exclude the token-vs-bearer auth scheme needs a PAT; unit-tested in ForgeReleaseSource/PatValidator.

- **GIVEN** a matching access token resolves for `codeberg:Conduction/private-build`
- **WHEN** the release listing runs
- **THEN** the request MUST carry `Authorization: token <token>` (not `Bearer`)

### Requirement: Trusted-allowlist management API [MVP]

The system MUST provide admin-only, password-confirmed endpoints to curate the trusted-source allowlist by adding and removing forge-qualified patterns, delegating persistence to `TrustedSourceList::setPatterns()`. The system MUST reject over-broad patterns that would trust an entire forge or everything.

#### Scenario: Curated add persists

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** an admin calls `POST /api/trusted-sources` with `{forge: "codeberg", owner: "Conduction", repo: "openregister"}` and a confirmed password
- **THEN** the system MUST construct the pattern `codeberg:Conduction/openregister`
- **AND** persist it into the allowlist via `TrustedSourceList::setPatterns()`
- **AND** a subsequent `GET /api/sources` MUST return `codeberg:Conduction/openregister` in `trustedPatterns`

#### Scenario: Curated add of an owner wildcard

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** an admin calls `POST /api/trusted-sources` with `{forge: "github", owner: "ConductionNL"}` (no `repo`) and a confirmed password
- **THEN** the system MUST construct the pattern `github:ConductionNL/*`
- **AND** persist it into the allowlist

#### Scenario: Over-broad glob rejected

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** an admin calls `POST /api/trusted-sources` with a payload that would yield `*`, `*/*`, `{forge}:*`, an empty owner, or an owner of exactly `*`
- **THEN** the system MUST NOT modify the allowlist
- **AND** the system MUST return HTTP 400 (or 422) with a clear message stating a concrete owner is required

#### Scenario: Unknown forge or bad charset rejected

@e2e exclude buildAndValidatePattern charset/forge validation is unit-tested.

- **GIVEN** an admin calls `POST /api/trusted-sources` with an unknown `forge`, or an `owner`/`repo` that does not match the safe charset `[A-Za-z0-9_.\-]+`
- **THEN** the system MUST NOT modify the allowlist
- **AND** the system MUST return HTTP 400 with a message naming the rejected forge or invalid characters

#### Scenario: Remove a pattern

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** the allowlist contains `codeberg:Conduction/openregister`
- **WHEN** an admin calls `DELETE /api/trusted-sources?pattern=codeberg%3AConduction%2Fopenregister` (pattern as a query parameter) and a confirmed password
- **THEN** the system MUST remove exactly that pattern and persist the result
- **AND** a subsequent `GET /api/sources` MUST NOT return that pattern in `trustedPatterns`

#### Scenario: Non-admin forbidden

@e2e tests/e2e/shell.spec.ts

- **GIVEN** a non-admin user calls `POST /api/trusted-sources` or `DELETE /api/trusted-sources?pattern=…`
- **THEN** the system MUST return HTTP 403 Forbidden
- **AND** the allowlist MUST remain unchanged

### Requirement: Source binding UI surfacing [MVP]

The admin UI MUST surface source binding so an admin can view an app's current binding and bind a forge repository to it, for both github and codeberg forges, using the existing `GET /api/source/{appId}/binding` and `POST /api/source/{appId}/bind` endpoints.

#### Scenario: Bind a Codeberg repo via the UI

@e2e tests/e2e/panels.spec.ts

- **GIVEN** an admin opens the Sources tab and selects an installed app
- **WHEN** the admin chooses forge `codeberg`, enters owner `Conduction` and repo `openregister`, and submits
- **THEN** the UI MUST call `POST /api/source/{appId}/bind` with the forge-qualified binding
- **AND** on success the version list for that app MUST load from the bound Codeberg source

#### Scenario: Current binding displayed

@e2e tests/e2e/panels.spec.ts

- **GIVEN** an app is bound to `github:ConductionNL/openregister`
- **WHEN** the admin opens the Sources tab for that app
- **THEN** the UI MUST display the current binding (forge, owner, repo) from `GET /api/source/{appId}/binding`

#### Scenario: Remove a binding via the UI

@e2e exclude a binding removal needs a trusted forge binding set up first; covered by src/components/SourcesPanel.spec.ts and tests/unit/Controller/ApiTest.php (testClearBindingRemovesTheBinding).

- **GIVEN** an app is bound to a forge source
- **WHEN** the admin clicks "Remove binding and use the App Store" on the Sources tab
- **THEN** the UI MUST call `DELETE /api/source/{appId}/binding` (admin-only, password-confirmed)
- **AND** the binding MUST be removed, audited as a `bind_source` entry naming the removed source, and the app MUST read its versions from the App Store again

#### Scenario: Allowlist surfaced for both forges

@e2e tests/e2e/panels.spec.ts

- **GIVEN** the allowlist contains `github:ConductionNL/*` and `codeberg:Conduction/*`
- **WHEN** the admin opens the Trusted sources tab
- **THEN** the UI MUST list both forge-qualified patterns

### Requirement: Install provenance and a one-off source on the page

The install result MUST show the installer's `integrityWarning` when an external install
went ahead without checksum verification, and MUST say when the package came from the local
artifact cache (`servedFromCache`). The version picker MUST offer the one-off `source`
override that the API and `occ versioniq:install --source` accept, for the version list and
the next install only, without changing the app's binding.

#### Scenario: An unverified install is flagged in the result

@e2e exclude an unverified install needs a forge release with no checksum asset; covered by src/utils/installResult.spec.ts and src/components/InstallResultNotices.spec.ts.

- **GIVEN** an external install that proceeded without checksum verification
- **WHEN** the install result renders
- **THEN** it MUST show "Installed without checksum verification." with the installer's warning, as an alert

#### Scenario: A one-off source from the picker

@e2e tests/e2e/versions.spec.ts

- **GIVEN** an app bound to the App Store
- **WHEN** the admin enters `github:owner/repo` under "Use another source this once" and loads versions
- **THEN** the version list MUST be requested with `?source=github:owner/repo`, and the next install MUST carry the same `source`
- **AND** the app's binding MUST NOT change, and the override MUST clear when another app is picked or the install succeeds

## User Stories

1. As a Conduction admin, I want to install Conduction apps directly from GitHub so I can roll back to a version that the App Store has already removed.
2. As a Conduction admin, I want a guarantee that I cannot accidentally install code from a third-party GitHub repo without explicitly allowlisting it first.
3. As a developer, I want to test a pre-release of an app from GitHub before it ships to the App Store.

## Acceptance Criteria

- [ ] `SourceRegistry` resolves both `appstore` and `github:owner/repo` source ids
- [ ] `GithubReleaseSource` lists versions from public GitHub repos
- [ ] `TrustedSourceList` defaults to `["ConductionNL/*"]` and rejects out-of-list sources before download
- [ ] External installs verify appId and version against the extracted `appinfo/info.xml`
- [ ] SHA-256 verification runs when `.sha256` sibling is present, and surfaces a warning when missing
- [ ] Source binding survives Nextcloud restart (persisted via `IConfig::setAppValue`)
- [ ] App Store install path (`SelectedReleaseInstallerService`) is unchanged
- [ ] All `composer check:strict` passes; PHPUnit suite passes
- [x] `SourceBinding` carries a `sha256` version→digest map with typed accessors; round-trips through `fromArray`/`toArray`; 200-entry cap
- [x] Digest recorded on every successful external install (sibling-verified or locally computed), never on failure
- [x] Recorded digest checked before extraction; mismatch → `sha_mismatch`, no filesystem change, recorded digest outranks a sibling `.sha256`
- [x] `acceptNewSha: true` bypasses once, replaces on success, is warning-logged (and audited when available)
- [x] Rebind to different source discards digests; same source preserves them
- [x] Binding API + external version list expose digests; picker badges recorded versions; mismatch dialog offers the explicit acceptance path

## Notes

- This spec governs the install **mechanism**. The discovery / search UI for finding apps to install lives in proposal 3 (`add-app-discovery-search`).
- Private-repo support and PAT management live in proposal 2 (`add-github-pat-management`). For this proposal, only public GitHub releases are supported.
- SHA-256 auto-pinning (trust-on-first-use) is implemented: the **first** install of a never-observed artifact is exactly as trusted as before (allowlist + appId/version checks + optional sibling checksum) — this hardening adds no first-contact protection. The sibling `.sha256` check remains a transport check; the recorded digest is the history check and wins on conflict. Cosign/Sigstore verification remains the future cryptographically complete answer (separate change); App Store installs keep the NC code-signing chain and are out of scope.
