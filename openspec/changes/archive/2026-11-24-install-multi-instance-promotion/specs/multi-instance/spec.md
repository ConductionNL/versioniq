# multi-instance Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-multi-instance-promotion](../../)

## Purpose

An admin sees the app versions of every connected Nextcloud instance in one table, and moves the exact package an earlier stage ran to this instance, with a waiting period and a rollback.

## ADDED Requirements

### Requirement: An admin connects other instances that run Versioniq

An admin MUST be able to add, edit and remove a connection to another Nextcloud instance that runs Versioniq, with a name, a stage label, an https address, and the username and app password of an admin account on that instance. Every change MUST require password confirmation and MUST be recorded in the audit trail. The password MUST be stored encrypted with `OCP\Security\ICrypto` and MUST NOT be returned by any endpoint. On save the system MUST read the remote manifest once and MUST show the result.

#### Scenario: An admin connects the acceptance instance

- **GIVEN** admin `alice` on production and an admin account `versioniq-reader` on `https://acc.example.org`
- **WHEN** she adds a connection named "Acceptance", stage acceptance, with that account's app password, and confirms her password
- **THEN** the Instances tab MUST list "Acceptance" with the time its manifest was read
- **AND** `GET /ocs/v2.php/apps/versioniq/api/instances` MUST return the connection without the password

#### Scenario: A non-admin account on the remote is refused

- **GIVEN** the app password belongs to an account that is not an admin on the remote instance
- **WHEN** alice saves the connection
- **THEN** the connection MUST be saved with the error "This account is not an admin on that instance."
- **AND** no remote data MUST be shown for it

### Requirement: Every Versioniq serves a read-only manifest of its apps

`GET /api/instance/manifest` MUST be admin-only and MUST return the instance name, its stage, the server version, the Versioniq version, and for every app the system may manage: the installed version, the state, the bound source id, the SHA-256 recorded for the installed version (null for an App Store app), and since when that version runs (null when unknown). The manifest MUST NOT contain tokens, passwords or file paths, and MUST NOT make an outbound call.

#### Scenario: Acceptance reports what it runs

- **GIVEN** `openregister` 2.4.1 was installed on acceptance through Versioniq from `github:ConductionNL/openregister` on 2026-09-01
- **WHEN** production reads the acceptance manifest
- **THEN** the entry for `openregister` MUST name 2.4.1, that source id, the recorded SHA-256 and `runningSince` 2026-09-01

#### Scenario: A non-admin cannot read the manifest

- **GIVEN** a user who is not an admin
- **WHEN** they call `GET /ocs/v2.php/apps/versioniq/api/instance/manifest`
- **THEN** the response MUST be 403 and carry no app data

### Requirement: The Instances tab shows every instance's versions side by side

The Instances tab MUST show one row per app and one column per instance in chain order, this instance included, each cell holding the installed version and, when known, since when it runs. A row whose versions differ MUST be marked, and a filter MUST show only those rows. A remote that could not be read MUST keep its last manifest with its age and the error. A remote whose Versioniq has no manifest endpoint MUST be shown as running an older Versioniq.

#### Scenario: An admin sees that production lags acceptance

- **GIVEN** acceptance runs `openregister` 2.4.1 and production runs 2.3.0
- **WHEN** alice opens the Instances tab on production
- **THEN** the `openregister` row MUST show 2.4.1 under Acceptance and 2.3.0 under Production, marked "Differs"

#### Scenario: A remote is down

- **GIVEN** the acceptance manifest was read yesterday and acceptance does not answer now
- **WHEN** alice clicks Refresh
- **THEN** the Acceptance column MUST keep yesterday's versions, say how old they are, and show the connection error

### Requirement: An admin promotes the exact package an earlier stage runs

On this instance, an admin MUST be able to promote the version an earlier stage runs, through `POST /api/app/{appId}/promote` with password confirmation. The system MUST read that stage's manifest again before installing, and MUST install through `InstallerService::installAppVersion()` with the earlier stage's source, so the trusted-source allowlist, the pin guard, the downgrade guard, maintenance mode and the outcome taxonomy apply unchanged. For a forge source the SHA-256 the earlier stage recorded MUST match the package this instance installs, and a different recorded digest on this instance MUST refuse the promotion. Each promotion MUST be recorded in the audit trail with operation `promote` and the connection it came from. After a promotion the page MUST offer the last-known-good rollback.

#### Scenario: Production takes the version acceptance tested

- **GIVEN** acceptance runs `openregister` 2.4.1 from `github:ConductionNL/openregister` with a recorded SHA-256, and production runs 2.3.0
- **WHEN** alice clicks "Promote from Acceptance" on the `openregister` row and confirms her password
- **THEN** production MUST install 2.4.1 from that source through the standard installer
- **AND** the audit trail MUST hold a `promote` row from 2.3.0 to 2.4.1 naming "Acceptance"

#### Scenario: A different package is refused

- **GIVEN** the release asset of `openregister` 2.4.1 was replaced after acceptance installed it
- **WHEN** alice promotes 2.4.1 from Acceptance
- **THEN** the install MUST be refused with a checksum mismatch before any file is swapped
- **AND** production MUST keep running 2.3.0

#### Scenario: A promotion is rolled back

- **GIVEN** production promoted `openregister` from 2.3.0 to 2.4.1 and the last-known-good record says 2.4.1
- **WHEN** alice picks "Roll back to 2.3.0" in the promotion result
- **THEN** the standard install flow for 2.3.0 MUST start with the downgrade dialog and its migration diff

### Requirement: A version waits a set number of days on the earlier stage

An admin MUST be able to set, on the Settings tab, how many days a version must run on the earlier stage before it may be promoted, from 0 to 90, where 0 switches the check off. A promotion inside that period, or whose running time on the earlier stage is unknown, MUST be refused with a 409 that names the days left, unless the request carries `overrideMinDays=1`. An override MUST be written into the audit message.

#### Scenario: Two weeks on acceptance first

- **GIVEN** the waiting period is 14 days and acceptance runs `openregister` 2.4.1 since 5 days ago
- **WHEN** alice promotes 2.4.1 from Acceptance
- **THEN** the response MUST be 409 saying 9 days are left
- **AND** nothing MUST be installed

#### Scenario: An urgent promotion is overridden and recorded

- **GIVEN** the same situation
- **WHEN** alice promotes with the override and confirms her password
- **THEN** 2.4.1 MUST be installed
- **AND** the `promote` audit row MUST say the waiting period was overridden with 9 days left
