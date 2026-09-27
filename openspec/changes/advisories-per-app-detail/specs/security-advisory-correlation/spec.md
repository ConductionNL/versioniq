# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [advisories-per-app-detail](../../)

## ADDED Requirements

### Requirement: Each product lists its recent advisories with CVE ids and dates

The advisory sweep MUST keep, for every stored advisory, its CVE ids, its publication date and a link when the source provides them, and MUST store per app and for the server a history of all advisories for that target, newest first, each marked as affecting or not affecting the installed version. The Advisories tab MUST list that history per target, showing for each advisory its CVE ids, its source id, its date, its severity and whether it affects the installed version, with the five most recent shown first and the rest behind a "Show all" control. The advisory badge on an app card MUST link to that app's list on the Advisories tab. Missing CVE ids or dates MUST be shown as missing, never left blank without a word.

#### Scenario: An admin reads the recent CVEs for one app

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** the last sweep stored two advisories for `fixtureapp`, one from 2026-08-01 with CVE-2026-1111 affecting the installed version and one from 2025-11-02 fixed in it
- **WHEN** the admin clicks the advisory badge on the `fixtureapp` card
- **THEN** the Advisories tab MUST open at `fixtureapp`
- **AND** the list MUST show CVE-2026-1111 first with its date and "Affects the installed version", and the 2025 advisory second with "Fixed in the installed version"

#### Scenario: An advisory without a CVE id says so

@e2e exclude covered by AdvisoriesPanel.spec.ts.

- **GIVEN** a stored advisory with a GHSA id and no CVE id
- **WHEN** the Advisories tab renders it
- **THEN** it MUST show the GHSA id and "No CVE id"

### Requirement: An affected version without an installable fix says why

For every target whose installed version is affected, the sweep MUST record a fix status: `installable` when the bound source lists the recommended version and does not mark it incompatible, `no_fix_published` when no fixed version is known, `not_in_source` when a fixed version is named but the bound source does not list it, or `needs_newer_server` when the source lists the fix but marks it and every later fixing version incompatible with this server. `GET /api/advisories` MUST also report when a pin holds an app below an installable fix. The Advisories tab MUST state the reason in one sentence next to the affected state. For the server row, the reason MUST be limited to `installable` or `no_fix_published`.

#### Scenario: No fix is published yet

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** `fixtureapp` 1.0.0 is affected by an advisory that names no patched version and covers every listed version
- **WHEN** the admin opens the Advisories tab after the next check
- **THEN** the `fixtureapp` block MUST say that no fixed version is published yet

#### Scenario: The fix needs a newer Nextcloud

@e2e exclude needs an App Store release that is incompatible with the CI server; covered by FixStatusTest.

- **GIVEN** `calendar` is affected, the fix is 4.8.0, and the App Store marks 4.8.0 and every later release incompatible with this server
- **WHEN** the admin opens the Advisories tab
- **THEN** the `calendar` block MUST say the fix needs a newer Nextcloud than this server runs

### Requirement: The instance has one patch compliance status

`GET /api/advisories` MUST return a compliance status for the server and all apps together: `compliant` when the last check is no older than twice the advisory check interval, no target's installed version is affected and every app was checked; `not_compliant` when any target's installed version is affected, with the number of affected targets per highest severity; `unknown` when no check has completed, when the last check is older than twice the interval, or when no target is affected but some apps could not be checked, with that number. The Apps tab and the Advisories tab MUST show the status in one line.

#### Scenario: One affected app makes the instance not compliant

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** a fresh check found `fixtureapp` 1.0.0 affected by a medium advisory and nothing else affected
- **WHEN** the admin opens the Apps tab
- **THEN** it MUST read "Not compliant: 1 app runs an affected version (1 medium)"
- **AND** after `fixtureapp` is updated to 1.0.1 and the check runs again, it MUST read "Compliant"

#### Scenario: An old check is not reported as compliant

@e2e exclude needs a check older than twice the interval, and the e2e run cannot age it; covered by ComplianceStatusTest.

- **GIVEN** the advisory interval is 6 hours and the last check completed 13 hours ago with nothing affected
- **WHEN** the admin opens the Apps tab
- **THEN** the status MUST be unknown and say the check is out of date
