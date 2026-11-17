# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [audit-external-changes](../../)

## ADDED Requirements

### Requirement: An app left disabled by an update is reported and, when it can run, re-enabled

When an app that was enabled is found disabled while its own version or the server version changed since Versioniq last saw it, the system MUST treat it as disabled by an update. When the installed version declares support for the running server and the admin switched on automatic re-enabling, the system MUST enable it and record a `reactivate` audit entry with the outcome. Otherwise it MUST notify every admin, naming the app and the change, and saying whether the installed version runs on this server, with the newest version that does when one is known. The app card MUST say "Disabled by the update to {version}" and MUST offer Enable when the version runs. A disable without a version change of the app or the server MUST be recorded as `external_disable` with its actor and MUST NOT be re-enabled. Automatic re-enabling MUST be off by default, and switching it MUST require password confirmation and be recorded in the audit trail.

#### Scenario: A server upgrade leaves an app off, and it runs

- **GIVEN** `forms` 4.3.0 was enabled and declares support for Nextcloud 32 to 34, the server was upgraded from 33 to 34, and `forms` is disabled afterwards
- **WHEN** the hourly check runs with automatic re-enabling off
- **THEN** admins MUST be notified that `forms` was left disabled and runs on this server
- **AND** its card MUST read "Disabled by the update to Nextcloud 34" with Enable

#### Scenario: With the switch on, Versioniq enables it

- **GIVEN** the same situation and automatic re-enabling is on
- **WHEN** the hourly check runs
- **THEN** `forms` MUST be enabled and the audit trail MUST hold a `reactivate` entry with status success

#### Scenario: An app that does not run stays off

- **GIVEN** `hermiq` 1.4.0 declares at most Nextcloud 33, the server moved to 34, and `hermiq` was disabled by the upgrade
- **WHEN** the hourly check runs, with the switch on or off
- **THEN** `hermiq` MUST stay disabled, and the notification MUST say it does not run on Nextcloud 34

#### Scenario: A deliberate disable is left alone

- **GIVEN** admin `bob` disables `calendar` on the Apps page, with no version change
- **WHEN** the hourly check runs with the switch on
- **THEN** `calendar` MUST stay disabled, and the History tab MUST show an `external_disable` entry by bob
