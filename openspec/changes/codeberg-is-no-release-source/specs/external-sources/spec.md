## REMOVED Requirements

### Requirement: Codeberg releases as a source [MVP]

**Reason**: ConductionNL uses GitHub only (standing rule, Codeberg retired 2026-07-23). The code already retired Codeberg as a forge an admin can pick: `ForgeRegistry` lists `codeberg` as retired and leaves it out of `selectableIds()`.

**Migration**: nothing breaks. Bindings, tokens and trusted patterns already stored under `codeberg` keep resolving to codeberg.org through the Forgejo API shape; see "A retired forge keeps what was stored under it".

## MODIFIED Requirements

### Requirement: Trusted-allowlist management API [MVP]

The system MUST provide admin-only, password-confirmed endpoints to curate the trusted-source allowlist by adding and removing forge-qualified patterns, delegating persistence to `TrustedSourceList::setPatterns()`. The system MUST reject over-broad patterns that would trust an entire forge or everything, and MUST refuse a new pattern for a retired forge (`codeberg`). A stored pattern of a retired forge MUST stay listed and removable.

#### Scenario: Curated add persists

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** an admin calls `POST /api/trusted-sources` with `{forge: "github", owner: "ConductionNL", repo: "openregister"}` and a confirmed password
- **THEN** the system MUST construct the pattern `github:ConductionNL/openregister`
- **AND** persist it into the allowlist via `TrustedSourceList::setPatterns()`
- **AND** a subsequent `GET /api/sources` MUST return `github:ConductionNL/openregister` in `trustedPatterns`

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

#### Scenario: A pattern for a retired forge is refused

@e2e exclude retired-forge refusal is unit-tested in tests/unit/Service/Source/ForgeRegistryTest.php (selectableIds).

- **GIVEN** an admin calls `POST /api/trusted-sources` with `{forge: "codeberg", owner: "Conduction"}` and a confirmed password
- **THEN** the system MUST NOT modify the allowlist
- **AND** the system MUST return HTTP 400 naming `codeberg` as a forge that is no longer offered

### Requirement: Source binding UI surfacing [MVP]

The admin UI MUST surface source binding so an admin can view an app's current binding and bind a forge repository to it, for every forge that is not retired (github, and the self-hosted forges an admin set), using the existing `GET /api/source/{appId}/binding` and `POST /api/source/{appId}/bind` endpoints.

#### Scenario: Bind a Codeberg repo via the UI

@e2e tests/e2e/panels.spec.ts

- **GIVEN** an admin opens the Sources tab and selects an installed app
- **WHEN** the admin opens the forge choice
- **THEN** `codeberg` MUST NOT be offered for a new binding
- **AND** an app that was bound to a `codeberg:` source before MUST still show that binding and load its versions from codeberg.org

#### Scenario: Bind a GitHub repo via the UI

@e2e tests/e2e/panels.spec.ts

- **GIVEN** an admin opens the Sources tab and selects an installed app
- **WHEN** the admin chooses forge `github`, enters owner `ConductionNL` and repo `openregister`, and submits
- **THEN** the UI MUST call `POST /api/source/{appId}/bind` with the forge-qualified binding
- **AND** on success the version list for that app MUST load from the bound GitHub source

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

- **GIVEN** the allowlist contains `github:ConductionNL/*` and a stored `codeberg:Conduction/*`
- **WHEN** the admin opens the Trusted sources tab
- **THEN** the UI MUST list both forge-qualified patterns

## ADDED Requirements

### Requirement: A retired forge keeps what was stored under it

The system MUST NOT offer a retired forge (`codeberg`) for a new binding, a new token or a new trusted pattern. A binding, token or trusted pattern already stored under a retired forge MUST keep working: its versions MUST still be listed and installed from that forge's API with the same allowlist, checksum and recorded-digest rules, and it MUST stay removable.

#### Scenario: An app bound to Codeberg before keeps updating

@e2e exclude retired-forge resolution is unit-tested in tests/unit/Service/Source/ForgeRegistryTest.php.

- **GIVEN** app `openregister` was bound to `codeberg:Conduction/openregister` before Codeberg was retired
- **WHEN** an admin opens its versions
- **THEN** the versions MUST come from `https://codeberg.org/api/v1/repos/Conduction/openregister/releases`
- **AND** installing one MUST go through the standard installer with the recorded-digest check

#### Scenario: No new Codeberg binding

- **WHEN** an admin calls `POST /api/source/{appId}/bind` with forge `codeberg`
- **THEN** the binding MUST be refused with 400 and nothing MUST be stored
