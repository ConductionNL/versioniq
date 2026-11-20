# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [write-guards-and-advisory-alerts](../../)

## ADDED Requirements

### Requirement: Every write action asks for the admin password

Every route that changes state MUST ask the admin to confirm their password first. This covers binding and clearing a source, trusting and untrusting a source, installing a version, pinning and unpinning, retrying an attempt, setting and clearing an update policy, the update and advisory settings, the instance settings, the Forgejo host, tokens and the cache. Read routes MUST NOT ask for it. occ commands run as the server user and MUST NOT ask for it, as "CLI trust context" says.

#### Scenario: An install waits for the password

@e2e exclude the password dialog belongs to Nextcloud core; the route attribute is covered by tests/unit/Controller/PasswordConfirmationTest.php.

- **GIVEN** an admin who has not confirmed their password in this session
- **WHEN** the admin installs a version, pins an app or saves a token
- **THEN** Nextcloud MUST ask for the password before the request runs
- **AND** without the password the request MUST be refused and nothing MUST change

#### Scenario: Reading needs no password

@e2e exclude covered by tests/unit/Controller/PasswordConfirmationTest.php.

- **WHEN** the admin opens the Apps tab or lists the versions of an app
- **THEN** no password MUST be asked

### Requirement: Core apps and Versioniq itself are never changed

Versioniq MUST refuse to list versions of, install or update a core app that Nextcloud always enables, and Versioniq itself. The refusal MUST hold on every path: the API, the occ commands and the automatic update job. The API MUST answer HTTP 403 with a message that names the reason. Core apps stay visible in the app list, marked as core, as "List Installed Apps" says.

#### Scenario: An install of a core app is refused

@e2e exclude a core app cannot be offered from the page; covered by tests/unit/Service/InstallerServiceTest.php.

- **GIVEN** `files` is a core app that Nextcloud always enables
- **WHEN** a request installs a version of `files`
- **THEN** the answer MUST be HTTP 403 with "This core app cannot be installed or updated from Versioniq."
- **AND** no file MUST change

#### Scenario: Versioniq does not replace itself

@e2e exclude Versioniq is left out of its own app list; covered by tests/unit/Service/InstallerServiceTest.php.

- **WHEN** an admin runs `occ versioniq:install versioniq 1.0.0`
- **THEN** the command MUST exit with code 2 and change nothing
