# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-one-click-updates](../../)

## ADDED Requirements

### Requirement: An admin updates to the safe version straight from the advisory

When an app's advisory state names a recommended version, the card MUST offer "Update to safe version {version}". It MUST show which advisory that version resolves, and after one confirmation it MUST install that version through the normal install endpoint, with every guard of that endpoint in place. The system MUST still never install it without the admin's action.

#### Scenario: An admin fixes an advisory in one action

- **GIVEN** `openregister` 2.3.0 is affected by advisory GHSA-xxxx and the recommended version is 2.3.4
- **WHEN** an admin clicks "Update to safe version 2.3.4" on the card and confirms
- **THEN** `openregister` 2.3.4 MUST be installed through the install endpoint
- **AND** after the next advisory sweep the card MUST no longer show the advisory
