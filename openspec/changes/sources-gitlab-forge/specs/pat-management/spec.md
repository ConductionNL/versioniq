# pat-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [sources-gitlab-forge](../../)

## ADDED Requirements

### Requirement: GitLab tokens are validated for read-only scopes

When an admin stores a token for the `gitlab` forge, the system MUST read the token's scopes and expiry from GitLab's own token endpoint, MUST accept it only when its scopes are within `read_api` and `read_repository`, and MUST reject a token that carries `api`, `write_repository` or `sudo`, naming the scope to drop.

#### Scenario: A full-access GitLab token is refused

- **GIVEN** an admin pastes a GitLab personal access token with scopes `api` and `read_repository`
- **WHEN** they save it on the Tokens tab
- **THEN** the token MUST be rejected with a message that names `api`
- **AND** no token MUST be stored
