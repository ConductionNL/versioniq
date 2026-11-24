# audit-trail Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [audit-security-reports](../../)

## ADDED Requirements

### Requirement: Advisory exposure and server updates are recorded in the history

After every advisory check the system MUST write an `advisory_open` audit entry for each advisory that newly affects the installed version of an app or of the server, and an `advisory_resolved` entry for each advisory that no longer affects it, stating whether it was resolved by an update, by a dismissal, by the advisory being withdrawn, or otherwise. Each such entry MUST name the advisory and its severity in the `advisories` column, and the versions involved. The system MUST NOT resolve an advisory for an app the check could not reach. When the check sees that the Nextcloud server version changed, it MUST write a `server_update` entry with the previous and the current version. These entries MUST follow the same best-effort, immutable and retention rules as every audit entry. Advisories already affecting the instance when tracking starts MUST be marked as such.

#### Scenario: An update resolves an advisory

@e2e tests/e2e/audit.spec.ts

- **GIVEN** a check found `fixtureapp` 1.0.0 affected by a fixture advisory
- **WHEN** an admin installs 1.0.1, which the advisory does not affect, and the check runs again
- **THEN** the history MUST hold an `advisory_open` entry for 1.0.0 and an `advisory_resolved` entry from 1.0.0 to 1.0.1 stating it was resolved by an update

#### Scenario: An unreached app keeps its advisory open

@e2e exclude covered by AdvisoryExposureTrackerTest.

- **GIVEN** an advisory affects `deck` and the next check cannot reach the source of `deck`
- **WHEN** the check completes
- **THEN** no `advisory_resolved` entry MUST be written for `deck`

#### Scenario: A server upgrade appears in the history

@e2e exclude the CI server cannot be upgraded during the e2e run; covered by AdvisoryExposureTrackerTest.

- **GIVEN** the last check saw server version 32.0.3
- **WHEN** the server runs 32.0.4 at the next check
- **THEN** the history MUST hold a `server_update` entry from 32.0.3 to 32.0.4

### Requirement: An admin reports on the security updates of a period

`GET /api/audit` MUST accept a period (`from`, `to`) and a filter for security updates only. A security update is a successful `install` or `server_update` entry after which an advisory was resolved by that update; each such row MUST name the advisories it fixed. The History tab MUST offer the period, the security filter, and an export of the filtered entries to CSV and to JSON, stating when an export was cut at 5000 rows.

#### Scenario: A monthly overview of security updates

@e2e tests/e2e/audit.spec.ts

- **GIVEN** this month `fixtureapp` was updated from 1.0.0 to 1.0.1, which resolved a fixture advisory, and another app was reinstalled at the same version
- **WHEN** an admin sets the History tab to this month with "Security updates only" and exports CSV
- **THEN** the list and the file MUST contain the `fixtureapp` update with the advisory it fixed
- **AND** they MUST NOT contain the reinstall
