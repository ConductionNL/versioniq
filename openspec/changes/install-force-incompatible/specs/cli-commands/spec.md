# cli-commands Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-force-incompatible](../../)

## ADDED Requirements

### Requirement: Force an incompatible version from the CLI

`occ versioniq:install <appId> <version> --force-incompatible` MUST install a version whose declared maximum is below the running server, with the same override, record, audit row and clean-up on failure as the page. Without the flag such an install MUST keep failing with the incompatible exit code. With the flag, a version that needs a newer server MUST still fail with that code, and stderr MUST say an override cannot help it.

#### Scenario: A provisioning script forces a known-good app

- **GIVEN** the server runs Nextcloud 33 and `hermiq` 1.4.0 declares `max-version="32"`
- **WHEN** an admin runs `occ versioniq:install hermiq 1.4.0 --force-incompatible`
- **THEN** 1.4.0 MUST be installed, `app_install_overwrite` MUST contain `hermiq`, and the exit code MUST be 0

#### Scenario: Without the flag nothing changes

- **WHEN** an admin runs `occ versioniq:install hermiq 1.4.0`
- **THEN** the exit code MUST be 7 and `app_install_overwrite` MUST NOT change
