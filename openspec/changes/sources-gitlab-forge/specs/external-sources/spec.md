# external-sources Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [sources-gitlab-forge](../../)

## MODIFIED Requirements

### Requirement: Forge abstraction [MVP]

The system MUST model each supported release forge as a `Forge` configuration entry carrying `id`, `dialect` (`github` or `gitlab`), `apiBaseUrl`, `webBaseUrl`, `authScheme` (`Bearer` or `token`), `exposesScopeHeader` (bool), and `tokenCreateUrl`, exposed through a `ForgeRegistry`. The generic release driver and the token validator MUST read forge behaviour from this configuration, and MUST build every endpoint from the forge's dialect, rather than hard-coding GitHub.

#### Scenario: Known forges registered

@e2e exclude ForgeRegistry contents are unit-tested.

- **GIVEN** the `ForgeRegistry`
- **WHEN** `get('github')`, `get('codeberg')` and `get('gitlab')` are called
- **THEN** `github` MUST resolve to dialect `github`, apiBaseUrl `https://api.github.com`, authScheme `Bearer`, exposesScopeHeader `true`, tokenCreateUrl `https://github.com/settings/tokens`
- **AND** `codeberg` MUST resolve to dialect `github`, apiBaseUrl `https://codeberg.org/api/v1`, authScheme `token`, exposesScopeHeader `false`, tokenCreateUrl `https://codeberg.org/user/settings/applications`
- **AND** `gitlab` MUST resolve to dialect `gitlab`, apiBaseUrl `https://gitlab.com/api/v4`, authScheme `Bearer`, unless the admin set a self-managed GitLab host

#### Scenario: Unknown forge rejected

@e2e exclude ForgeRegistry rejects unknown forges, unit-tested.

- **GIVEN** the `ForgeRegistry`
- **WHEN** `get('bitbucket')` is called
- **THEN** the registry MUST throw, so unknown forges cannot reach an outbound call

## ADDED Requirements

### Requirement: GitLab releases as a source

An admin MUST be able to bind an app to `gitlab:{path}`, where the path is a GitLab project path with one or more groups, on gitlab.com or on a self-managed GitLab host set on the Sources tab. Every path segment MUST pass the existing character check, and `.` and `..` segments MUST be refused. Versions MUST come from the project's releases, release notes from their descriptions, and the archive from the release asset link that matches the asset pattern, with the same allowlist, checksum, recorded-digest and cache rules as a GitHub release. A GitLab-bound app MUST read "no advisory source" in the advisory check. For every forge, a stored token MUST be sent only to the forge's own host, never to an asset link on another host.

#### Scenario: An admin installs an app released on self-managed GitLab

- **GIVEN** the admin set `https://gitlab.example.nl` as GitLab host and trusted `gitlab:gemeente/*`
- **WHEN** the admin binds `zaakapp` to `gitlab:gemeente/team/zaakapp` and opens its versions
- **THEN** the versions MUST be the project's release tags, newest first, with their descriptions as release notes
- **AND** installing one MUST download the release asset link that matches the pattern

#### Scenario: A traversal path is refused

- **WHEN** an admin tries to bind an app to `gitlab:group/../other`
- **THEN** the binding MUST be refused with 400 and nothing MUST be stored

#### Scenario: A token never leaves the forge's host

- **GIVEN** a GitLab release whose asset link points at `https://downloads.example.org`, and a stored GitLab token
- **WHEN** an admin installs that release
- **THEN** the download request to `downloads.example.org` MUST carry no `Authorization` header
