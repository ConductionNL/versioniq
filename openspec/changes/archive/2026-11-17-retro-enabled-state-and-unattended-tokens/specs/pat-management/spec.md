# pat-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [retro-enabled-state-and-unattended-tokens](../../)

## ADDED Requirements

### Requirement: Background jobs use only tokens shared with all admins

A background job runs with no signed-in user: the advisory refresh, the automatic update and the pin reconcile. When such a job fetches from a private source, it MUST choose only from tokens an admin shared with all admins, and MUST NOT use a token its owner kept private. Within the shared tokens the normal rules MUST apply: the forge must match, an expired token is skipped, and the most specific target pattern wins. When no shared token matches, the job MUST fetch without a token, and a source that then answers 404 MUST NOT be read as clean.

#### Scenario: The nightly update reaches a private repository through a shared token

- **GIVEN** an app is bound to the private repository `acme/tool` and an admin shared a GitHub token for `acme/*` with all admins
- **WHEN** the automatic update job checks `acme/tool` for a newer version
- **THEN** the request MUST carry the shared token

#### Scenario: A private token is never used by a background job

- **GIVEN** the only token matching `acme/tool` belongs to one admin and is not shared
- **WHEN** the advisory refresh job reads `acme/tool`
- **THEN** the request MUST NOT carry that token

#### Scenario: A signed-in admin still uses their own private token

- **GIVEN** an admin owns a private token for `acme/tool`
- **WHEN** that admin opens the version list of the app bound to `acme/tool`
- **THEN** the request MUST carry that admin's token
