# external-sources Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-manual-upload](../../)

## ADDED Requirements

### Requirement: An admin installs a version from an uploaded archive

An admin MUST be able to upload a `.tar.gz` app archive and see its app id, version, SHA-256 and whether the App Store's signature verifies it, before anything is installed. Installing it MUST require strict password confirmation and MUST apply every guard of the normal install path: manageable app, downgrade guard, pin guard, app id and version match, migration diff, backup and restore. An archive the App Store signature verifies MUST install as a signed install. An unsigned archive MUST be refused unless an admin switched on unsigned uploads and either the expected SHA-256 matches or the admin accepted the shown one. The install MUST be audited with source `upload` and the SHA-256, and MUST NOT change the app's source binding.

#### Scenario: An admin installs a supplier build

- **GIVEN** unsigned uploads are on, and the admin has `hermiq-1.4.1.tar.gz` from the supplier with a known SHA-256
- **WHEN** the admin opens `hermiq`, picks "Install from a file", uploads the archive, pastes the SHA-256 and clicks Install
- **THEN** `hermiq` 1.4.1 MUST be installed
- **AND** the History tab MUST show an install row with source `upload` and that SHA-256
- **AND** the `hermiq` source binding MUST be unchanged

#### Scenario: An unsigned upload is refused by default

- **GIVEN** unsigned uploads are off, and the uploaded archive is not a version the App Store signs
- **WHEN** the admin clicks Install
- **THEN** the install MUST be refused with a message that names the setting
- **AND** no file of the installed app MUST change
