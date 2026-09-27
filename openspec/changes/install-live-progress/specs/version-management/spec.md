# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-live-progress](../../)

## ADDED Requirements

### Requirement: An admin sees each stage of an install while it runs

While an install runs, the Apps tab MUST show its stages as they start and end: resolving the release, downloading, checking the signature or checksum, extracting, validating `info.xml`, comparing migrations, swapping files, running migrations and repair steps, and restoring after a failure. The running stage MUST show how long it has taken. The page MUST say that maintenance mode is on and that it should stay open. Progress MUST be served by an admin-only endpoint that other requests of the same session can reach while the install runs. Reporting progress MUST NOT change or fail any install step.

#### Scenario: An admin watches an update

- **GIVEN** an admin installs `openregister` 2.4.1 from the App Store
- **WHEN** the download has finished and the signature check runs
- **THEN** the page MUST show "Downloading" as done and "Checking the signature" as running with its elapsed time
- **AND** when the install returns, the page MUST show the result panel as before

#### Scenario: A failure names the stage it stopped in

- **GIVEN** an admin installs a forge release whose checksum does not match
- **WHEN** the checksum stage fails
- **THEN** the checklist MUST mark "Checking the checksum" as failed and show no later stage as done
