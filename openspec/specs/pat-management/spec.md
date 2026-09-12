---
status: implemented
---

# PAT Management Specification

**Status**: implemented
**Standards**: GitHub REST API v2022-11-28 (User endpoint, OAuth-Scopes header), Nextcloud `OCP\Security\ICrypto`
**Feature tier**: MVP

## Purpose

Encrypted Personal Access Token storage for the Versioniq app, so admins can install apps from private GitHub repositories. Tokens are validated for least-privilege scope on upload, encrypted at rest, never returned over the API in plaintext, and automatically picked up by `GithubReleaseSource` when the bound `owner/repo` matches a stored PAT.
## Requirements
### Requirement: PAT storage [MVP]

The system MUST persist PATs in a dedicated table with encrypted token bytes, owner attribution, and a `target_pattern` glob that scopes the PAT to specific `owner/repo` paths.

#### Scenario: Upload a classic PAT

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** an admin POSTs `{label: "ConductionNL prod", kind: "classic", targetPattern: "ConductionNL/*", token: "ghp_abc..."}`
- **WHEN** the system validates and encrypts the token
- **THEN** a row MUST be inserted with `owner_uid = current admin uid`, `encrypted_token = ICrypto::encrypt(token)`, `token_hint = "ghp_abcd...xxxx"`, `shared_with_admins = false`
- **AND** the response MUST contain only the redacted record (no `encrypted_token`, no plaintext)

#### Scenario: PAT not exposed via API after creation

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** a PAT exists in the database
- **WHEN** the admin calls `GET /api/pats`
- **THEN** the response MUST NOT contain `encrypted_token`
- **AND** the response MUST NOT contain the plaintext token
- **AND** the response MUST contain `tokenHint` (first 4 + last 4 chars of plaintext, captured at upload)

#### Scenario: Per-admin default; optional share

@e2e exclude per-admin visibility needs a second admin; unit-tested.

- **GIVEN** admin A uploads a PAT with `sharedWithAdmins = false`
- **WHEN** admin B calls `GET /api/pats`
- **THEN** admin A's PAT MUST NOT appear in the response
- **GIVEN** admin A then PATCHes the PAT with `sharedWithAdmins = true`
- **WHEN** admin B calls `GET /api/pats`
- **THEN** admin A's PAT MUST appear in the response
- **AND** the row MUST still show `owner_uid = A` (admin B can use it but not delete it)

### Requirement: Encryption at rest [MVP]

PATs MUST be encrypted via `\OCP\Security\ICrypto::encrypt()` before persistence and decrypted only inside a tightly scoped callback in `PatManager::useToken()`.

#### Scenario: Plaintext never reaches a property

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** a PAT is being used to authenticate a GitHub fetch
- **WHEN** the request runs
- **THEN** the plaintext value MUST NOT be stored on any class property
- **AND** MUST NOT be returned across a method boundary except as the argument to the `useToken` callback
- **AND** MUST NOT be logged

### Requirement: PAT validation on upload [MVP]

The system MUST probe a PAT against `GET {forge.apiBaseUrl}/user` (using the forge's auth scheme — `Bearer` for GitHub, `token` for Codeberg) before persisting, with the SSRF guard `nextcloud: ['allow_local_address' => false]`. For forges that expose token scopes (GitHub), the system MUST reject tokens with broader scope than Versioniq needs. For forges that do NOT expose token scopes (Codeberg/Forgejo, `exposesScopeHeader = false`), the system MUST accept a valid token with an `unverifiable_scope` best-effort warning. GitHub token-kind prefix detection (`ghp_` / `github_pat_`) remains GitHub-specific; Codeberg tokens are treated as opaque.

#### Scenario: Classic PAT with `repo` scope only — accepted

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** the admin uploads a GitHub `ghp_*` token with `X-OAuth-Scopes: repo`
- **THEN** the system MUST accept the PAT
- **AND** `last_validated_scopes` MUST contain `["repo"]`

#### Scenario: Classic PAT with extra write scope — rejected

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** the admin uploads a GitHub `ghp_*` token with `X-OAuth-Scopes: repo, write:packages, admin:org`
- **THEN** the system MUST reject with HTTP 400
- **AND** the error message MUST list the disallowed scopes (`write:packages, admin:org`)

#### Scenario: Fine-grained GitHub PAT — best-effort acceptance

@e2e exclude GitHub fine-grained tokens expose no scopes to validate; the best-effort path is unit-tested.

- **GIVEN** the admin uploads a GitHub `github_pat_*` token and the User endpoint returns 200
- **THEN** the system MUST accept the PAT
- **AND** record `unverifiable_scope: true` in `last_validated_scopes.warnings`
- **AND** the API response MUST surface this warning so the UI can display "GitHub did not expose configured permissions; please verify they are read-only."

#### Scenario: Codeberg token — validated via Forgejo, accepted unverifiable-scope

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** the admin uploads a Codeberg access token for `forge = codeberg`
- **WHEN** the system probes `https://codeberg.org/api/v1/user` with `Authorization: token <token>` and the endpoint returns 200
- **THEN** the system MUST accept the PAT
- **AND** because Forgejo does not expose token scopes (no `X-OAuth-Scopes`), the system MUST record an `unverifiable_scope` warning (worded for Codeberg/Forgejo)
- **AND** the API response MUST surface this warning to the UI

#### Scenario: Invalid or revoked token

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** the forge User endpoint returns 401
- **THEN** the system MUST reject with HTTP 400
- **AND** the error message MUST be "Token is invalid or revoked"

#### Scenario: Expiration captured

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** a GitHub response includes `github-authentication-token-expiration: 2026-08-15 12:00:00 UTC`
- **THEN** the system MUST parse and persist `expires_at = 2026-08-15T12:00:00Z`

### Requirement: Authenticated GitHub fetches [MVP]

When a PAT visible to the current admin matches the source binding's forge AND `owner/repo`, the system MUST attach the forge's auth header (`Authorization: Bearer <token>` for GitHub, `Authorization: token <token>` for Codeberg) to the forge request and MUST update the PAT's `last_used_at` timestamp. PAT matching MUST be forge-scoped: a PAT only authenticates a binding on the same forge.

#### Scenario: Private GitHub repo accessible via PAT

@e2e exclude covered above.

- **GIVEN** admin A is bound to source `github:ConductionNL/private-build` and has uploaded a GitHub PAT with `target_pattern = ConductionNL/*`
- **WHEN** `GET /api/app/private-build/versions` runs
- **THEN** the GitHub API request MUST include `Authorization: Bearer <token>`
- **AND** the system MUST return the private repo's releases
- **AND** `pats.last_used_at` MUST be updated for that PAT

#### Scenario: Private Codeberg repo accessible via Codeberg PAT

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** admin A is bound to source `codeberg:Conduction/private-build` and has uploaded a Codeberg token with `forge = codeberg`, `target_pattern = Conduction/*`
- **WHEN** the version list runs
- **THEN** the Codeberg request MUST include `Authorization: token <token>`
- **AND** the system MUST return the private repo's releases
- **AND** `pats.last_used_at` MUST be updated for that PAT

#### Scenario: No matching PAT — unauthenticated path

@e2e tests/e2e/external-sources.spec.ts

- **GIVEN** there is no PAT covering `codeberg:Conduction/openregister`
- **WHEN** the version list runs
- **THEN** the request MUST be unauthenticated (public repo path)

#### Scenario: Expired PAT skipped

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** a PAT with `expires_at` in the past
- **WHEN** `PatResolver::findFor` is called
- **THEN** the expired PAT MUST be skipped
- **AND** the next-priority PAT (or unauthenticated) MUST be used

#### Scenario: Legacy github PAT still authenticates (backward compat)

@e2e exclude the legacy-forge-default backward-compat is unit-tested.

- **GIVEN** a PAT row that predates this change (no `forge` set, reads back as `github`)
- **WHEN** it is resolved for a `github:ConductionNL/private-build` binding
- **THEN** it MUST match and authenticate exactly as before

### Requirement: PAT management API [MVP]

The system MUST expose endpoints for listing, creating, updating, deleting, and re-probing PATs, plus a deeplink helper for the forge token-creation flow. The deeplink helper MUST support GitHub (classic and fine-grained) and Codeberg, reading the token-create URL from the forge configuration.

#### Scenario: Deeplink for classic GitHub PAT

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** an admin calls `GET /api/pats/deeplink?kind=classic`
- **THEN** the response MUST contain a `url` of the form `https://github.com/settings/tokens/new?scopes=repo&description=Nextcloud%20App%20Versions...`
- **AND** the response MUST contain an `instructions` array

#### Scenario: Deeplink for fine-grained GitHub PAT

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** an admin calls `GET /api/pats/deeplink?kind=fine-grained`
- **THEN** the response MUST contain `url = https://github.com/settings/personal-access-tokens/new`
- **AND** an `instructions` array describing the required Contents+Metadata read-only permissions

#### Scenario: Deeplink for Codeberg token

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** an admin requests a Codeberg token-creation deeplink
- **THEN** the response MUST contain `url = https://codeberg.org/user/settings/applications`
- **AND** an `instructions` array describing creating a least-privilege access token and pasting it back into Versioniq

#### Scenario: Delete restricted to owner

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** admin A's PAT exists, shared with admins
- **WHEN** admin B calls `DELETE /api/pats/{id}`
- **THEN** the system MUST return 403
- **AND** the PAT MUST remain in the database

### Requirement: Admin removal cleans up PATs [MVP]

When a Nextcloud user is deleted, all PATs owned by that uid MUST be deleted.

#### Scenario: User deletion sweeps PATs

@e2e tests/e2e/pat-validation.spec.ts

- **GIVEN** admin A owns PATs P1 (private) and P2 (shared)
- **WHEN** an admin deletes user A
- **THEN** the user-deleted listener MUST delete P1 and P2
- **AND** subsequent calls to `GET /api/pats` from admin B MUST NOT return P2

### Requirement: Forge attribution on PATs [MVP]

Each stored PAT MUST carry a `forge` attribute (`github` | `codeberg`, default `github`) so a token is matched only to bindings on the same forge. The `versioniq_pats` table MUST gain a `forge` column via an additive, idempotent migration with a `github` default, so existing rows remain valid without a data migration.

#### Scenario: Migration adds forge column with github default

@e2e exclude a DB-migration assertion, verified in migration unit coverage.

- **GIVEN** an existing `versioniq_pats` table with rows that predate this change
- **WHEN** the `forge`-column migration runs
- **THEN** the column MUST be added as a non-null string with default `github`
- **AND** existing rows MUST read back `forge = github`
- **AND** re-running the migration MUST be a no-op (guarded by `hasColumn`)

#### Scenario: PAT resolution is forge-scoped

@e2e exclude the forge-scoped resolution is unit-tested in PatResolver.

- **GIVEN** a stored Codeberg PAT (`forge = codeberg`, `target_pattern = Conduction/*`) and a stored GitHub PAT (`forge = github`, `target_pattern = ConductionNL/*`)
- **WHEN** `PatResolver::findFor` resolves a token for binding `codeberg:Conduction/private-build`
- **THEN** only the Codeberg PAT MUST be considered (forge MUST match in addition to owner/repo glob)
- **AND** a GitHub PAT MUST NOT authenticate a Codeberg request

### Requirement: PAT management UI surfacing [MVP]

The admin UI MUST surface access-token (PAT) management so an admin can list redacted tokens, add a token, edit its label and share flag, delete it, and open a per-forge token-creation deeplink — using the existing PAT endpoints with no backend change beyond what `codeberg-forge-support` (#2) added. The UI MUST let the admin choose the forge (github|codeberg) when adding a token and when requesting a deeplink.

#### Scenario: List tokens redacted

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** PATs visible to the current admin exist
- **WHEN** the admin opens the Tokens tab
- **THEN** the UI MUST list the tokens from `GET /api/pats`
- **AND** MUST display only redacted fields (label, forge, token hint, share flag) and never a plaintext token or encrypted bytes

#### Scenario: Add a token via the UI

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** an admin opens the add-token form
- **WHEN** the admin selects forge `codeberg`, enters a label and target (owner [+ optional repo]), pastes a token, and submits with a confirmed password
- **THEN** the UI MUST call `POST /api/pats` with `forge`, `label`, derived `targetPattern`, and `token`
- **AND** on success the new redacted token MUST appear in the list

#### Scenario: Edit label and share flag

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** a token owned by the current admin is listed
- **WHEN** the admin changes its label or toggles share-with-admins and confirms their password
- **THEN** the UI MUST call `PATCH /api/pats/{id}` with the changed fields
- **AND** the updated redacted record MUST be shown

#### Scenario: Delete a token via the UI

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** a token owned by the current admin is listed
- **WHEN** the admin deletes it and confirms their password
- **THEN** the UI MUST call `DELETE /api/pats/{id}`
- **AND** the token MUST be removed from the list on success

#### Scenario: Per-forge deeplink

@e2e tests/e2e/pat-management.spec.ts

- **GIVEN** an admin clicks "create a token" for forge `codeberg`
- **WHEN** the UI requests `GET /api/pats/deeplink?forge=codeberg`
- **THEN** the UI MUST present the returned `url` and `instructions` for that forge

### Requirement: PAT expiry warnings [MVP]

A daily background job MUST, for every PAT with a known `expiresAt`, notify the token's owner when expiry is ≤14 days away, again when ≤3 days away, and once upon/after expiry — at most one notification per threshold per token, tracked persistently. Notifications MUST name the token label and forge, state days remaining (or "expired"), and link the per-forge token-renewal deeplink. Tokens without a known expiry MUST NOT be probed or notified.

#### Scenario: 14-day warning fires once

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** a GitHub PAT "conduction-bot" expiring in 12 days, not yet warned
- **WHEN** the job runs on two consecutive days
- **THEN** the owner MUST receive exactly one `pat_expiring` notification (from the first run) naming the token, forge, and days remaining, linking the renewal deeplink

#### Scenario: Escalation at 3 days and at expiry

@e2e exclude the expiry-warning escalation runs in the daily job over aged tokens; unit-tested.

- **GIVEN** the same token reaches 2 days remaining, then expires
- **WHEN** the job runs on each of those days
- **THEN** one 3-day-threshold notification and one `pat_expired` notification MUST be delivered (each once)

#### Scenario: Unknown expiry is left alone

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** a Codeberg token whose validation captured no expiry
- **WHEN** the job runs
- **THEN** no notification MUST be sent for it

### Requirement: Expiry state in the PAT API and UI [MVP]

`GET /api/pats` MUST expose a derived `expiryState` (`ok` | `expiring` (≤14 d) | `expired` | `unknown`) per token. The Tokens panel MUST badge `expiring` tokens with days remaining (warning tone) and `expired` tokens (error tone), and MUST show "expiry unknown" neutrally for `unknown`.

#### Scenario: Badges reflect state

@e2e exclude the expiry badges need a token with a known expiry; the four badge states are covered by TokensPanel vitest.

- **GIVEN** tokens in states ok, expiring (5 d), expired, unknown
- **WHEN** the Tokens panel renders
- **THEN** the expiring token MUST show a warning badge "expires in 5 days", the expired one an error badge, the unknown one a neutral "expiry unknown", and the ok one no expiry badge

## User Stories

1. As an admin, I want to install apps from private ConductionNL repos so I can deploy customer-specific builds without leaving Nextcloud.
2. As a security-conscious admin, I want Versioniq to refuse PATs with more rights than it needs so I cannot accidentally grant write access.
3. As an admin who left a team, I want my uploaded PATs to disappear when my account is removed so they don't outlive my access.

## Acceptance Criteria

- [ ] PAT table created via migration; idempotent re-run
- [ ] Classic PATs with non-`repo`/`public_repo` scopes are rejected on upload
- [ ] Fine-grained PATs are accepted with an `unverifiable_scope` warning surfaced to the UI
- [ ] Plaintext tokens never appear in API responses or logs
- [ ] Bound private repo lists versions when matching PAT exists; falls back unauthenticated otherwise
- [ ] Per-admin scoping by default; share-with-admins flag works
- [ ] User deletion sweeps the deleted user's PATs

## Notes

- This proposal does not handle GitHub Apps or OAuth flows. PATs only.
- Token storage is deliberately **not** the Nextcloud per-user crypto chain — `ICrypto` uses the system secret so PATs survive password changes (and admin handover, via the share toggle).
- Expiry warnings landed later via the `add-pat-expiry-warnings` change (archived 2026-07-23): a daily job with 14 d / 3 d / expired thresholds plus derived `expiryState` in the API and UI. Tokens whose forge never disclosed an expiry stay unmonitored by design.
