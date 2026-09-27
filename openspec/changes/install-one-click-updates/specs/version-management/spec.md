# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-one-click-updates](../../)

## ADDED Requirements

### Requirement: An admin updates one app or all apps in one action

A card whose app has a newer version this server can run MUST offer "Update to {version}", which MUST show the move and the release notes in between and install that version through the normal install endpoint after one confirmation. The Apps tab MUST offer "Update all" when any app has such a version: it MUST list those apps, leave pinned apps unticked with the reason, ask for the password at most once for the run, install the ticked apps one after the other through the same endpoint, show each outcome as it lands, and continue after a failure. No action here MAY bypass a guard of the install endpoint.

#### Scenario: An admin updates one app from its card

- **GIVEN** `openregister` 2.3.0 is installed and the last sweep recorded 2.4.1 as the newest version this server can run
- **WHEN** an admin clicks "Update to 2.4.1" on the card and confirms
- **THEN** `openregister` 2.4.1 MUST be installed through the install endpoint
- **AND** the History tab MUST show the install row

#### Scenario: An admin updates all apps and one fails

- **GIVEN** `openregister`, `calendar` and `hermiq` have newer versions, `hermiq` is pinned, and the `calendar` download fails its checksum
- **WHEN** an admin clicks "Update all", keeps the default ticks, and confirms their password once
- **THEN** `hermiq` MUST be listed unticked as pinned and MUST NOT be installed
- **AND** `openregister` MUST read updated and `calendar` MUST read failed with its category and hint
- **AND** the admin MUST NOT be asked for the password a second time within the platform's confirmation window
