# version-pinning Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-pins-checks-and-pending](../../)

## ADDED Requirements

### Requirement: The app list filters to pinned apps

The status bar of the Apps tab MUST show how many apps are pinned. Choosing it MUST filter the list to the pinned apps, each showing its pinned version, who pinned it and the reason. The filter MUST combine with the other filters and MUST be listed under "Active" with a way to clear it.

#### Scenario: An admin reviews every pin

- **GIVEN** three of 42 apps are pinned
- **WHEN** the admin chooses "Pinned 3"
- **THEN** the list MUST show exactly those three apps with their pinned version, who pinned them and why
