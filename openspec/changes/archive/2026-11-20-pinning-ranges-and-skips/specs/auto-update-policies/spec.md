# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [pinning-ranges-and-skips](../../)

## ADDED Requirements

### Requirement: A policy can hold manual installs to its level

A per-app policy MUST carry a switch that holds Versioniq's own install path to the policy's level. With the switch on and the level `patch` or `minor`, a real install of a newer version outside the level MUST be refused with 409 and category `outOfRange`, unless the request carries an explicit range override, which MUST be audited. The switch MUST NOT claim to hold Nextcloud's own updater, and its help text MUST say so. A stored policy without the switch MUST read as off.

#### Scenario: A manual major upgrade is held back

- **GIVEN** `openregister` 2.3.0 has policy `patch` with "Also hold manual installs to this level" on
- **WHEN** an admin picks 3.0.0 in the version picker and clicks Install
- **THEN** the install MUST be refused with category `outOfRange`, naming the patch level
- **AND** confirming the override MUST install 3.0.0 and write a `range_override` audit row
