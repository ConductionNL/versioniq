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
- **WHEN** `get('github')` and `get('gitlab')` are called
- **THEN** `github` MUST resolve to dialect `github`, apiBaseUrl `https://api.github.com`, authScheme `Bearer`, exposesScopeHeader `true`, tokenCreateUrl `https://github.com/settings/tokens`
- **AND** `gitlab` MUST resolve to dialect `gitlab`, apiBaseUrl `https://gitlab.com/api/v4`, authScheme `Bearer`, unless the admin set a self-managed GitLab host
- **AND** `selectableIds()` MUST NOT contain `codeberg`, a retired forge kept only so what was stored under it resolves (see "A retired forge keeps what was stored under it")

#### Scenario: Unknown forge rejected

@e2e exclude ForgeRegistry rejects unknown forges, unit-tested.

- **GIVEN** the `ForgeRegistry`
- **WHEN** `get('bitbucket')` is called
- **THEN** the registry MUST throw, so unknown forges cannot reach an outbound call

## ADDED Requirements

### Requirement: A self-hosted Forgejo or Gitea host is a release source

An admin MUST be able to set one self-hosted Forgejo or Gitea host on the Sources tab through `PUT /api/forges/forgejo`, admin only and password confirmed, and read it back through `GET /api/forges/forgejo`. The host MUST be an https address with no username, password, query or fragment, because tokens are sent to it; any other value MUST be refused with 400 and leave the stored host unchanged. An empty value MUST clear the host. Once a host is set, an admin MUST be able to bind an app to `forgejo:{owner}/{repo}` and list and install its releases through the Forgejo release API at `{host}/api/v1`, with the same allowlist, checksum and recorded-digest rules as a GitHub release. Bindings, tokens and trusted patterns stored under `codeberg` MUST keep resolving to codeberg.org.

#### Scenario: An admin binds an app to a self-hosted Forgejo host

@e2e exclude the host form and the forge registry are unit-tested in tests/unit/Controller/ForgeControllerTest.php and tests/unit/Service/Source/ForgeRegistryTest.php; an e2e run needs a reachable Forgejo fixture.

- **GIVEN** the admin set `https://git.example.org` as the Forgejo host and trusted `forgejo:gemeente/*`
- **WHEN** the admin binds `zaakapp` to `forgejo:gemeente/zaakapp` and opens its versions
- **THEN** the versions MUST come from `https://git.example.org/api/v1/repos/gemeente/zaakapp/releases`
- **AND** installing one MUST go through the standard installer with the recorded-digest check

#### Scenario: A host that is not plain https is refused

- **WHEN** an admin sets `http://git.example.org` or `https://user:secret@git.example.org` as the Forgejo host
- **THEN** the response MUST be 400 and the stored host MUST stay as it was

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

#### Scenario: A token only travels over https to the forge's own port

- **GIVEN** a release whose asset link is `http://github.com/...` or `https://github.com:8443/...`, and a stored GitHub token
- **WHEN** an admin installs that release
- **THEN** the download request MUST carry no `Authorization` header
