# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-upgrade-readiness](../../)

## ADDED Requirements

### Requirement: Versions say whether they run on this server's PHP

Each listed version MUST carry, next to `serverCompatible`, whether it runs on the PHP version this server runs (`phpCompatible`: true, false, or null when the source gives no range). The App Store release's `phpVersionSpec` and a forge release's `info.xml` PHP range MUST both feed it. The version picker MUST show a version that does not run on this PHP as such, before the admin installs it.

#### Scenario: An admin sees a PHP mismatch before installing

- **GIVEN** the server runs PHP 8.1 and `openregister` 3.0.0 declares PHP 8.2 or higher
- **WHEN** an admin opens the `openregister` version list
- **THEN** 3.0.0 MUST show that it does not run on this PHP version
