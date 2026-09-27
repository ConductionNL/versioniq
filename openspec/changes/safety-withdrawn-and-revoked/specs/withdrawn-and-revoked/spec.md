# withdrawn-and-revoked Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [safety-withdrawn-and-revoked](../../)

## Purpose

An admin learns when code they already run lost the trust it was installed with, and never reinstalls a withdrawn version by accident.

## ADDED Requirements

### Requirement: Installed apps are rechecked against a current revocation list

Every day the system MUST check the signing certificate of every installed App Store app that is not shipped with the server against the newest revocation list it can trust: the server's shipped list, or a newer list fetched from an address the admin can change or empty, accepted only when its signature validates against Nextcloud's root certificate. Installs MUST use the same list. An app whose certificate is revoked MUST get a "Certificate revoked" badge, and every admin MUST be notified once per app and certificate. The notification MUST say that Nextcloud revokes a certificate to withdraw an app it knows to be compromised or malicious. The system MUST disable such an app only when the admin switched that on, and MUST audit it.

#### Scenario: A certificate revoked after install is caught

- **GIVEN** `badapp` was installed from the App Store last month, and Nextcloud has since revoked its certificate in a newer, validly signed revocation list
- **WHEN** the daily certificate recheck runs
- **THEN** every admin MUST receive one notification naming `badapp`
- **AND** the `badapp` card MUST show "Certificate revoked"
- **AND** `badapp` MUST stay enabled while automatic disabling is off

#### Scenario: A list with a bad signature is ignored

- **GIVEN** the configured address serves a revocation list whose signature does not validate against Nextcloud's root certificate
- **WHEN** the recheck runs
- **THEN** the system MUST use the server's shipped list and MUST NOT flag any app from the fetched list

### Requirement: A withdrawn installed version is marked and never reinstalled by accident

When a source listing succeeds and no longer contains the installed version, the app's card MUST show "Version withdrawn by the publisher". The system MUST record every version that an earlier successful listing contained and a later successful listing does not, and MUST refuse to install such a version with category `withdrawn`, even when a stale cached listing still contains it. A failed listing MUST NOT mark any version withdrawn.

#### Scenario: An admin learns the running version was withdrawn

- **GIVEN** `hermiq` 1.4.0 is installed and the publisher deleted the 1.4.0 release on GitHub
- **WHEN** the availability sweep has run and the admin opens the Apps tab
- **THEN** the `hermiq` card MUST show "Version withdrawn by the publisher"

#### Scenario: A stale listing does not bring a withdrawn version back

- **GIVEN** the App Store withdrew `openregister` 2.4.0, and the store is unreachable so the stale cached catalogue still lists 2.4.0
- **WHEN** an admin tries to install `openregister` 2.4.0
- **THEN** the install MUST be refused with category `withdrawn` and a hint to pick another version
