# changelog-visibility Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [releases-version-facts](../../)

## ADDED Requirements

### Requirement: A version its publisher marks as breaking is flagged

Every version entry MUST carry `breaking`, true when its full release notes, read before truncation, contain a heading "Breaking change" or "Breaking changes" at any level, with or without a leading warning sign, or a line starting with `BREAKING CHANGE:` or `BREAKING-CHANGE:`. The picker MUST show a "Breaking" badge on such a version, separate from the major version badge. When any version between the installed and the chosen version is breaking, the range summary and the downgrade dialog MUST name those versions.

#### Scenario: A breaking minor release is named before the upgrade

- **GIVEN** `openregister` 2.3.0 is installed and the notes of 2.4.0 carry a "⚠ BREAKING CHANGES" heading
- **WHEN** an admin selects 2.5.0
- **THEN** 2.4.0 MUST show the "Breaking" badge
- **AND** the range summary MUST say "Includes releases marked as breaking: 2.4.0."

#### Scenario: A marker at the end of long notes still counts

- **GIVEN** a release whose notes run past 8 KiB and end with a `BREAKING CHANGE:` line
- **WHEN** the version list loads
- **THEN** that entry MUST carry `breaking: true` although its returned changelog is truncated

### Requirement: Known issues of a version are shown before and after installing it

When a version's release notes contain a section headed "Known issues" or "Known problems", the picker MUST show that section on its own, above the rest of the notes, as plain text. An admin MUST be able to add a known issue to a version, with a text, a workaround and an optional https link, and to remove it, through admin-only, password-confirmed endpoints that are recorded in the audit trail. Admin notes MUST show on that version in the picker, and the app card MUST say "Known issue in the installed version" while an app runs a version with an admin note.

#### Scenario: A publisher adds a known issue after release

- **GIVEN** `hermiq` 1.4.0 was released on GitHub, and a week later its release body gained a "Known issues" section: "LDAP sync stops after 1000 users; set the page size to 500"
- **WHEN** an admin opens the version list of `hermiq`
- **THEN** 1.4.0 MUST show that section under "Known issues", above its release notes

#### Scenario: An admin records a problem found in testing

- **GIVEN** `openregister` 2.4.1 is installed
- **WHEN** admin `alice` adds the known issue "Export to CSV times out on large registers" with the workaround "Export per schema" to 2.4.1 and confirms her password
- **THEN** the 2.4.1 entry MUST show the issue and its workaround
- **AND** the `openregister` card MUST say "Known issue in the installed version"
- **AND** the audit trail MUST hold a `settings` row by alice naming `openregister` 2.4.1
