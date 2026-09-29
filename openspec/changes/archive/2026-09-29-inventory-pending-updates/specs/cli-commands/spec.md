# cli-commands Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-pending-updates](../../)

## ADDED Requirements

### Requirement: List pending updates from the CLI

`occ versioniq:updates` MUST print, for every app in the stored availability snapshot, the installed version, the newest version this server can run, the release lines behind and whether the app is pinned. `--json` MUST print the same data and the snapshot time as JSON. `--refresh` MUST run the availability sweep and store it before printing. `--outside-policy` MUST limit the output to apps past the admin's limit. The command MUST exit 1 when no snapshot exists and `--refresh` was not given.

#### Scenario: A script reads pending updates as JSON

- **GIVEN** the last sweep recorded `openregister` 2.3.0 with 2.4.1 available
- **WHEN** an admin runs `occ versioniq:updates --json`
- **THEN** stdout MUST be valid JSON with `checkedAt` and an `updates` entry for `openregister` naming 2.3.0 and 2.4.1
- **AND** the exit code MUST be 0

#### Scenario: Never checked is not reported as nothing to do

- **GIVEN** no availability sweep has completed
- **WHEN** an admin runs `occ versioniq:updates`
- **THEN** the command MUST say that no check has run and exit 1
