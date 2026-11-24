# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-force-incompatible](../../)

## ADDED Requirements

### Requirement: An admin forces a version past its declared maximum, and Versioniq lifts it at the next major

Every version entry whose `serverCompatible` is false MUST carry `serverTooNew`, true when the running server is above the version's declared maximum and every lower bound holds. For such a version the picker MUST offer "Install anyway", and for a disabled app whose installed version is such a version the card MUST offer "Enable anyway". A version that needs a newer server MUST NOT be offered either, and the picker MUST say it cannot be forced. Both actions MUST explain the risk, MUST require password confirmation, MUST add the app to the system config list `app_install_overwrite` when it is not there, and MUST remove it again when the install or enable fails and Versioniq added it. After a success, Versioniq MUST record the override with the server major and MUST write an audit row. The card MUST say that the app is forced on that major and that the override is lifted at the next major upgrade. Once the server runs a higher major, Versioniq MUST remove the entries it added, MUST disable the app when its installed version does not declare support for the new major, MUST record the lift in the audit trail and MUST notify admins. Versioniq MUST NOT remove an entry it did not add.

#### Scenario: An admin runs an app on a major its publisher has not declared yet

- **GIVEN** the server runs Nextcloud 33.0.2, and `hermiq` 1.4.0 declares `max-version="32"` and `min-version="30"`
- **WHEN** admin `alice` picks "Install anyway" on 1.4.0, reads the warning and confirms her password
- **THEN** `app_install_overwrite` MUST contain `hermiq`, 1.4.0 MUST be installed through the standard installer
- **AND** the card MUST read "Forced to run on Nextcloud 33. Lifted at the next major upgrade."

#### Scenario: A version that needs a newer server cannot be forced

- **GIVEN** the server runs Nextcloud 32.0.5 and `openregister` 3.0.0 declares `min-version="33"`
- **WHEN** alice opens its version list
- **THEN** 3.0.0 MUST read "Needs a newer Nextcloud; cannot be forced" and MUST NOT offer "Install anyway"

#### Scenario: A failed forced install leaves no override behind

- **GIVEN** `hermiq` is not in `app_install_overwrite`
- **WHEN** a forced install of 1.4.0 fails its checksum
- **THEN** `app_install_overwrite` MUST NOT contain `hermiq`

#### Scenario: The override is lifted after the upgrade to the next major

- **GIVEN** Versioniq forced `hermiq` 1.4.0 on Nextcloud 33, and the server was upgraded to 34 without clearing the list
- **WHEN** the reconcile job runs
- **THEN** `hermiq` MUST be removed from `app_install_overwrite` and disabled, because 1.4.0 does not declare support for 34
- **AND** admins MUST get a notification naming `hermiq`, and the audit trail MUST record the lift

#### Scenario: An override set by someone else is left alone

- **GIVEN** `calendar` was forced through Nextcloud's own force enable, with no Versioniq record
- **WHEN** the server moves to the next major and the reconcile job runs
- **THEN** Versioniq MUST NOT change the `calendar` entry
