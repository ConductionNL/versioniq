# lifecycle-signals Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-lifecycle-signals](../../)

## Purpose

An admin learns that an app is abandoned or out of support, or that the server is about to leave support, before it breaks, and moves an app to its successor in one step.

## ADDED Requirements

### Requirement: Abandoned and unsupported apps are flagged on their card

For every installed app the system MUST record whether it was removed from the App Store, whether its forge repository is archived, whether its newest release is older than twelve months, and whether no listed release supports a Nextcloud major that is still maintained. The Apps tab MUST show the strongest of these on the app's card. A shipped app MUST NOT be flagged as removed from the App Store.

#### Scenario: An admin sees an app that left the App Store

- **GIVEN** `oldmaps` is bound to the App Store, is not shipped with the server, and the App Store catalogue no longer lists it
- **WHEN** the availability sweep has run and an admin opens the Apps tab
- **THEN** the `oldmaps` card MUST show "Removed from the App Store"

#### Scenario: An app with no release for any maintained server is out of support

- **GIVEN** every Nextcloud major that `legacyapp` supports has passed its end-of-life date
- **WHEN** an admin opens the Apps tab
- **THEN** the `legacyapp` card MUST show "End of support"

### Requirement: Admins are warned before the server leaves support

The system MUST read the end-of-life date of each Nextcloud major from a feed address the admin can change or empty, MUST show the running major's date on the Advisories tab, and MUST notify every admin 90 days and 30 days before that date, once per threshold. An empty or unreachable feed MUST switch the warnings off and MUST NOT mark any app as out of support.

#### Scenario: Admins get a 90-day warning

- **GIVEN** the running Nextcloud major reaches end of life in 90 days according to the feed
- **WHEN** the daily warning job runs
- **THEN** every admin MUST receive one notification naming the major and the date
- **AND** the job MUST NOT send the 90-day notification again the next day

### Requirement: An admin replaces an app with its successor in one action

The system MUST keep a successor list of `{from, to, source}` entries, shipped with Versioniq and extendable by an admin. When the successor's source lists a version this server can run, the old app's card MUST offer "Replace with {successor}". Replacing MUST require the admin's password, MUST install the successor through the normal install path first, and MUST disable the old app only after that install succeeded. It MUST NOT delete the old app's data, and it MUST write an audit row for each step.

#### Scenario: An admin moves to a renamed app

- **GIVEN** the successor list has `{from: "oldname", to: "newname", source: "appstore"}` and the App Store lists `newname` 1.0.0 for this server
- **WHEN** an admin clicks "Replace with newname" on the `oldname` card and confirms with their password
- **THEN** `newname` 1.0.0 MUST be installed and enabled, and `oldname` MUST be disabled
- **AND** the History tab MUST show an install row for `newname` and a replace row for `oldname`

#### Scenario: A failed successor install leaves the old app running

- **GIVEN** the same successor entry and the `newname` download fails its checksum
- **WHEN** an admin replaces `oldname`
- **THEN** the result MUST show the install failure
- **AND** `oldname` MUST stay enabled
