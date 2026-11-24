# release-alerts Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [releases-new-release-alerts](../../)

## Purpose

An admin hears about a new version of an app Nextcloud does not announce, can follow an app before installing it, and can read the same releases in a feed reader.

## ADDED Requirements

### Requirement: A new release is detected after each availability sweep and logged

After each availability sweep has been stored, the system MUST compare, for every app the sweep reached without an error, the newest version with the one the previous stored sweep recorded. A higher newest version MUST be logged as a new release with the app, the version, the source, whether it runs on this server, its release date when known, and the time it was detected. An app absent from the previous sweep, and the very first sweep, MUST set a baseline and MUST NOT log a release. The log MUST keep at most 200 entries, newest first.

#### Scenario: A forge release is logged

- **GIVEN** the previous sweep recorded `hermiq` newest 1.4.0 from `github:ConductionNL/hermiq`, and 1.5.0 is published
- **WHEN** the next availability sweep is stored
- **THEN** the release log MUST gain `hermiq` 1.5.0 with that source and the detection time

#### Scenario: The first sweep announces nothing

- **GIVEN** a fresh install where no sweep was stored before
- **WHEN** the first availability sweep is stored
- **THEN** the release log MUST stay empty

### Requirement: Admins are notified of new releases in the scope they choose

For every logged release inside the scope, the system MUST send every admin a Nextcloud notification naming the app and the version, and saying so when the version does not run on this server. An admin MUST be able to set the scope to `forge` (apps whose source is not the App Store, and followed apps), `all`, or `off`; the default MUST be `forge`, because Nextcloud's own update notification already covers App Store apps. Changing the scope MUST require password confirmation and MUST be recorded in the audit trail.

#### Scenario: A forge release reaches the bell

- **GIVEN** the scope is `forge` and `hermiq` 1.5.0 was just logged
- **WHEN** admin `alice` opens her notifications
- **THEN** she MUST see "New version of hermiq: 1.5.0"

#### Scenario: An App Store release is left to Nextcloud by default

- **GIVEN** the scope is `forge` and `calendar` 5.2.0 from the App Store was just logged
- **WHEN** the notifications are sent
- **THEN** Versioniq MUST NOT notify about `calendar`
- **AND** with the scope set to `all`, the next such release MUST be notified

### Requirement: An admin follows the releases of an app that is not installed

An admin MUST be able to follow an app that is not installed, with its source, from the Discover tab or by app id, and to stop following it, through admin-only, password-confirmed endpoints recorded in the audit trail. The availability sweep MUST include every followed app that is not installed, and its new releases MUST be logged and notified like those of an installed app.

#### Scenario: An admin watches an app before adopting it

- **GIVEN** `forms` is not installed and the Discover tab shows it from the App Store
- **WHEN** alice picks "Follow releases" and confirms her password
- **THEN** the Alerts tab MUST list `forms` as followed
- **AND** its next new version MUST be logged and notified

### Requirement: New releases are available as an RSS feed for admins

`GET /apps/versioniq/feed/releases.rss` MUST return the release log as RSS 2.0, one item per release with the app, the version, the source, a link to the Versioniq admin page and a publication date, which is the release date when known and the detection time otherwise. It MUST require an admin, signed in with a session or with Basic authentication and an app password, and MUST answer 403 or 401 to anyone else.

#### Scenario: A feed reader signs in with an app password

- **GIVEN** alice created an app password and the log holds `hermiq` 1.5.0
- **WHEN** her feed reader requests the feed with Basic authentication
- **THEN** the response MUST be `application/rss+xml` with an item titled "hermiq 1.5.0"

#### Scenario: The feed is not public

- **GIVEN** a request without credentials, or with those of a user who is not an admin
- **WHEN** it asks for the feed
- **THEN** the response MUST NOT contain any app or version
