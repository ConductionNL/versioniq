# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [autoupdate-schedules](../../)

## MODIFIED Requirements

### Requirement: Global kill switch and window [MVP]

`auto_update_enabled` (default `false`), `auto_update_window` (default `01:00-05:00`, format `HH:MM-HH:MM`, windows crossing midnight supported) and `auto_update_schedule` (the weekdays the window runs on, per kind of update, default every day for every kind) MUST be admin-configurable via the settings UI and readable via the API. The window MUST run only on a day the schedule allows for at least one kind of update. The day that counts MUST be the date the window opened, read in Nextcloud's `default_timezone` (UTC when unset or invalid), so a run after midnight inside a midnight-crossing window belongs to the day it opened. With the switch off, per-app policies remain stored but inert, and the UI MUST say so. Cron expressions and more than one window MUST NOT be offered.

#### Scenario: Kill switch inert-but-stored

@e2e tests/e2e/auto-update.spec.ts

- GIVEN policies exist and `auto_update_enabled` is false
- WHEN the admin views the app list
- THEN policy badges MUST render with an "automation disabled" indication
- AND the job MUST not act

#### Scenario: Midnight-crossing window

@e2e exclude the midnight-crossing window logic is unit-tested.

- GIVEN window `23:00-03:00`
- WHEN the job fires at 00:30
- THEN it MUST be considered inside the window

#### Scenario: The window runs only on chosen weekdays

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** automatic updates are on and an admin set a schedule that leaves out today's weekday for every kind of update
- **WHEN** the job runs inside the window today
- **THEN** it MUST NOT query any source and MUST NOT install anything
- **AND** once today's weekday is added to the schedule, the next run inside the window MUST install the due update

#### Scenario: A night that starts on Friday is a Friday run

@e2e exclude the e2e run cannot set the server clock to a time after midnight; covered by AutoUpdateScheduleTest with a fixed clock.

- **GIVEN** the window is `23:00-03:00` and the schedule allows Friday but not Saturday
- **WHEN** the job wakes at 00:30 on Saturday inside the window that opened on Friday
- **THEN** the run MUST be treated as a Friday run and MAY install updates

#### Scenario: An instance without a schedule runs every day

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `auto_update_schedule` was never set
- **WHEN** the job wakes inside the window on any day
- **THEN** every kind of update MUST be treated as due, as before this change

## ADDED Requirements

### Requirement: Each kind of update has its own schedule

The system MUST classify each candidate version by the step from the installed version: `patch` (same `major.minor`), `minor` (same major) or `major` (any other step, and any version that does not parse as `major.minor.patch`). An admin MUST be able to give each kind its own weekdays, including none, and MUST be able to limit a kind to the first of its weekdays in a month. On each run the job MUST install, per app, the highest version within the app's policy level whose kind is due that day, and MUST ignore candidates whose kind is not due. `PUT /api/auto-update/settings` MUST validate the whole schedule before writing anything and MUST answer HTTP 400 for an unknown kind, a day outside 1 to 7, or a non-boolean monthly flag.

#### Scenario: Patches go in on a patch night while the minor waits

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `openregister` 2.3.0 has policy `minor`, its source lists 2.3.4 and 2.4.0, patches run every day and minors only on a weekday that is not today
- **WHEN** the job runs inside the window today
- **THEN** it MUST install 2.3.4
- **AND** it MUST NOT install 2.4.0 until a run on a day minors are due

#### Scenario: Majors run monthly

@e2e exclude needs two fixed dates a week apart and the e2e run cannot set the server clock; covered by AutoUpdateScheduleTest.

- **GIVEN** majors are set to Saturday, first of the month only
- **WHEN** the window opens on Saturday 5 October and on Saturday 12 October
- **THEN** a major update MAY run on 5 October
- **AND** no major update MUST run on 12 October

#### Scenario: A bad schedule is refused whole

@e2e exclude covered by tests/unit/Controller/ApiTest.php.

- **GIVEN** an admin sends a schedule with day 8 for minors and a new window `02:00-04:00`
- **WHEN** they call `PUT /ocs/v2.php/apps/versioniq/api/auto-update/settings`
- **THEN** the response MUST be 400 and name the minor days
- **AND** neither the schedule nor the window MUST change

### Requirement: The overview shows when each kind of update runs next

The Automatic updates overview on the Apps tab MUST show, for each kind of update, the weekdays it runs on and the date and time of its next run in the zone the window uses, as returned by `GET /api/policies` in `nextRuns`. A kind with no days MUST read as never updated automatically. When no kind has any day, the overview MUST say that nothing runs.

#### Scenario: An admin sees the next run per kind

@e2e tests/e2e/auto-update.spec.ts

- **GIVEN** automatic updates are on, patches run Monday to Friday and majors never
- **WHEN** the admin opens the Apps tab
- **THEN** the overview MUST list patch updates with Monday to Friday and a next run date
- **AND** it MUST say that major updates never run automatically
