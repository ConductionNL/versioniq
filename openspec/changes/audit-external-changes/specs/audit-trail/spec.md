# audit-trail Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [audit-external-changes](../../)

## ADDED Requirements

### Requirement: Version changes made outside Versioniq are recorded with their trigger

The system MUST keep the last version and enabled state it saw for every app, and MUST update it after every install through Versioniq so that such an install is never recorded twice. For every app, pinned or not, a version change reported by `OCP\App\Events\AppUpdateEvent` MUST be recorded as an `external_update` audit entry with the actor (the session user, or `system`), the previous and the new version, the bound source, and the trigger: the `occ` command and its arguments on the command line, with password and token values redacted, or the request method and path in a web request, or `unknown`. At least every hour the system MUST compare every app with what it last saw, and MUST record a change no event reported as `external_update` with a trigger saying it was found by the hourly check. The first comparison after install MUST set a baseline and record nothing. A pinned app MUST keep its `pin_drift` entry as well.

#### Scenario: An update on Nextcloud's Apps page is recorded

- **GIVEN** `calendar` is not pinned and Versioniq last saw it at 5.1.0
- **WHEN** admin `bob` updates it to 5.2.0 on Nextcloud's own Apps page
- **THEN** the History tab MUST show an `external_update` entry by bob from 5.1.0 to 5.2.0 with the web request path as the trigger

#### Scenario: A command-line update names the command

- **GIVEN** Versioniq last saw `openregister` at 2.3.0
- **WHEN** an operator runs `occ app:update openregister`
- **THEN** the entry MUST record 2.3.0 to the new version, actor `system`, and the trigger `occ app:update openregister`

#### Scenario: Files replaced by hand are found within the hour

- **GIVEN** Versioniq last saw `forms` at 4.3.0, and an operator restored a backup holding 4.2.1 without any update event
- **WHEN** the hourly check runs
- **THEN** an `external_update` entry from 4.3.0 to 4.2.1 MUST say it was found by the hourly check

#### Scenario: Versioniq's own install is not counted twice

- **WHEN** an admin installs `openregister` 2.4.0 through Versioniq
- **THEN** the History tab MUST show the `install` entry only, and no `external_update` entry for it
