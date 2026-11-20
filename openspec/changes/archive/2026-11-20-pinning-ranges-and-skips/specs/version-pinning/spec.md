# version-pinning Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [pinning-ranges-and-skips](../../)

## ADDED Requirements

### Requirement: An admin skips one version and keeps later ones

An admin MUST be able to skip one version of an app, with an optional reason, and undo that. A skipped version MUST NOT be chosen by the nightly job and MUST NOT be shown as the pending update, while later versions MUST be offered as usual. The version picker MUST mark a skipped version with its reason and an Unskip action. A skipped version MAY still be installed by hand after one confirmation that names the skip. The advisory recommendation MUST ignore skips. Skipping and unskipping MUST be admin-only and audited.

#### Scenario: A bad release is skipped and the fix still arrives

- **GIVEN** `openregister` 2.3.3 is installed with policy `patch`, and the admin skipped 2.3.4 with the reason "breaks the import"
- **WHEN** the source lists 2.3.4 and 2.3.5 and the nightly job runs inside the window
- **THEN** the job MUST install 2.3.5 and MUST NOT install 2.3.4
- **AND** the version picker MUST show 2.3.4 as skipped with "breaks the import"
