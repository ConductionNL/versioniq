# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [safety-rollback-backup-and-preflight](../../)

## ADDED Requirements

### Requirement: The pre-flight check refuses an install that does not fit on disk

Before downloading, the pre-flight check MUST measure the free space in the apps folder and in the temp folder. It MUST require at least three times the package size in the apps folder and two times in the temp folder, assuming 50 MB when the size is unknown. A refusal MUST be classified `preflight_space`, MUST NOT be HTTP 500, and its hint MUST name the free and the needed space. The install progress MUST show the free space that was found.

#### Scenario: Not enough space

- **GIVEN** the apps folder has 40 MB free and the package is 20 MB
- **WHEN** an admin installs the version
- **THEN** the install MUST be refused before download with category `preflight_space` and HTTP 409
- **AND** the hint MUST say 40 MB is free and 60 MB is needed

#### Scenario: The check shows what it found

- **GIVEN** enough free space
- **WHEN** an admin installs a version
- **THEN** the progress MUST show "Environment checked" with the free space and that the app folder is writable
