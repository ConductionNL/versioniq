# cli-commands Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-live-progress](../../)

## ADDED Requirements

### Requirement: The install command prints each stage as it happens

`occ versioniq:install` MUST print each install stage when it starts, in the same names the page shows, and then the outcome as today. With `--json` it MUST print only the single JSON outcome.

#### Scenario: A provisioning script shows where an install is

- **WHEN** an admin runs `occ versioniq:install openregister 2.4.1`
- **THEN** the console MUST show the download, signature check, extraction, migration and finalize stages in order before the outcome line
- **AND** the exit code MUST follow the documented exit-code map
