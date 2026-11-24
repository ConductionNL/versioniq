---
status: implemented
---

# Auto-Update Policies Specification

**Status**: implemented
**OpenSpec changes**: [releases-minimum-age](../../changes/archive/2026-11-24-releases-minimum-age/), [safety-update-checks](../../changes/archive/2026-11-24-safety-update-checks/), [autoupdate-proposed-updates](../../changes/archive/2026-11-24-autoupdate-proposed-updates/), [autoupdate-run-batching](../../changes/archive/2026-11-24-autoupdate-run-batching/), [autoupdate-schedules](../../changes/archive/2026-11-24-autoupdate-schedules/), [autoupdate-security-first](../../changes/archive/2026-11-24-autoupdate-security-first/)
**Standards**: OCP\BackgroundJob\TimedJob, OCP\IAppConfig, OCP\Notification\IManager, Semantic Versioning 2.0.0 (level comparison)
**Feature tier**: MVP

## Purpose

Turn Versioniq from a repair tool into bounded, observable update automation: per-app semver-level policies executed nightly through the app's own verified installer path, honoring pins, constrained to a maintenance window, defaulting to off, and reporting every action. Explicitly not a silent `occ app:update --all` clone — the value is the bounds and the reporting.

## Requirements

### Requirement: Per-app update policy [MVP]

Admins MUST be able to set, read, and clear a per-app policy `level` ∈ `none|patch|minor|all|security` via `GET /api/policies`, `PUT /api/app/{appId}/policy`, `DELETE /api/app/{appId}/policy`; writes MUST require password confirmation and MUST record `setBy`/`setAt`. Policy MUST be persisted as `policy.{appId}` app config JSON. Absent policy means `none`. Non-admins MUST receive 403. The level `security` MUST install nothing except the fix the last advisory check recommends for an installed version it found affected.

#### Scenario: Set a patch policy

@e2e tests/e2e/auto-update.spec.ts

- WHEN admin `alice` calls `PUT /api/app/openregister/policy` with `{level: "patch"}` and confirms her password
- THEN `policy.openregister` MUST record level patch, setBy alice, setAt ISO-8601
- AND `GET /api/policies` MUST list it

#### Scenario: Invalid level rejected

@e2e tests/e2e/install-effects.spec.ts

- WHEN `PUT .../policy` is called with `{level: "yolo"}`
- THEN the response MUST be 400 and no policy MUST be written

#### Scenario: Set a security-only policy

@e2e tests/e2e/patch-policy.spec.ts

- **GIVEN** admin `alice` on the Apps tab
- **WHEN** she picks "Security fixes only" for `openregister` and confirms her password
- **THEN** `policy.openregister` MUST record level `security`, setBy alice
- **AND** the card MUST show the policy as security fixes only

### Requirement: Nightly policy execution through the standard installer [MVP]

A `TimedJob` that wakes every 15 minutes MUST, once per opening of the window, when `auto_update_enabled` is true and the current time in Nextcloud's `default_timezone` (UTC when unset or invalid) is inside `auto_update_window` (default `01:00-05:00`): for every app with policy level ≠ none — skip pinned apps entirely; list versions from the app's bound source; select the highest available version that is (a) strictly newer than installed, (b) compatible, (c) within the policy level (patch: same major.minor; minor: same major; all: any); and install it via `InstallerService::installAppVersion` with all standard verification, backup/restore, and outcome classification. The job MUST never downgrade, MUST attempt a given (appId, version) at most once (recording attempts), and MUST proceed to the next app after any failure.

#### Scenario: Patch-level update applied

@e2e tests/e2e/jobs.spec.ts

- GIVEN `openregister` installed at 2.3.0, policy patch, source lists 2.3.4 and 2.4.0
- WHEN the job runs inside the window
- THEN 2.3.4 MUST be installed via the standard installer path
- AND 2.4.0 MUST NOT be considered

#### Scenario: Pinned app skipped

@e2e tests/e2e/jobs.spec.ts

- GIVEN `openregister` has any policy and a pin
- WHEN the job runs
- THEN `openregister` MUST be skipped without a source query

#### Scenario: Failed attempt is not retried

@e2e exclude tests/e2e/jobs.spec.ts ("a failed auto-update attempt is not retried") drives the failure and asserts the attempt is recorded, but a second run in the same window is a no-op by design, so the refusal to reattempt is covered by tests/unit/BackgroundJob/AutoUpdateJobTest.php (testAlreadyAttemptedCandidateIsNotReattempted).

- GIVEN the 2.3.4 install failed yesterday (recorded)
- WHEN the job runs again and the source still offers 2.3.4 as the qualifying target
- THEN the job MUST NOT reattempt 2.3.4

#### Scenario: An admin retries a blocked version

@e2e exclude a blocked version only exists after the cron job failed an install; covered by src/components/PolicySelector.spec.ts and tests/unit/Controller/ApiTest.php (testRetryAttemptForgetsTheVersion).

- GIVEN the 2.3.4 install failed and is recorded
- WHEN the admin opens the Apps tab
- THEN the app's policy MUST list 2.3.4 as skipped because an earlier attempt failed, with a Retry action
- AND Retry MUST call `DELETE /api/app/{appId}/attempts/{version}` (admin-only, password-confirmed), after which the next run inside the window MAY attempt 2.3.4 again

#### Scenario: Disabled or outside the window is a no-op

@e2e tests/e2e/jobs.spec.ts drives the disabled half; every jobs test opens the window to 00:00-23:59, so the outside-the-window half is covered by tests/unit/BackgroundJob/AutoUpdateJobTest.php (testOutsideTheWindowIsANoOpWithNoSourceQueries).

- GIVEN `auto_update_enabled` false, or a run at 13:00 with window `01:00-05:00`
- WHEN the job fires
- THEN no source queries and no installs MUST happen

---

### Requirement: Every auto-update outcome is reported [MVP]

Each attempted install MUST produce an admin notification: success (app, old → new version) or failure (app, target version, classified category + hint). Notifications MUST use the existing Notifier infrastructure. A job run that updates nothing MUST NOT notify.

#### Scenario: Success notification

@e2e tests/e2e/jobs.spec.ts

- GIVEN the job updates `openregister` 2.3.0 → 2.3.4
- THEN admins MUST receive a notification naming the app and both versions

#### Scenario: Failure notification carries the classification

@e2e exclude tests/e2e/jobs.spec.ts ("a failed auto-update attempt is not retried") asserts an auto_update_failure notification reaches admins, but not its hint; the category-derived hint is covered by tests/unit/BackgroundJob/AutoUpdateJobTest.php (testFailedInstallRecordsTheAttemptAndNotifiesWithTheClassification).

- GIVEN the 2.3.4 install fails with category `checksum_mismatch`
- THEN admins MUST receive a failure notification naming the category-derived hint

---

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

### Requirement: Scheduled and blocked updates on one view

The Automatic updates section MUST list, in one place, every app with an auto-update policy
(its level and the window it runs in) and every version an earlier automatic attempt failed
on, each with a Retry that calls `DELETE /api/app/{appId}/attempts/{version}`. Pending updates
(a newer version available now) are not listed: the page has no per-app availability without
querying every source.

#### Scenario: One overview of scheduled and blocked updates

@e2e exclude a blocked row only exists after the cron job failed an install; covered by src/components/AutoUpdateOverview.spec.ts.

- **GIVEN** two apps with a policy, one with a failed version 2.3.4
- **WHEN** the admin opens the Apps tab
- **THEN** the overview MUST list both apps as scheduled with the window, and 2.3.4 as blocked with a Retry
- **AND** while automatic updates are off it MUST say nothing runs until they are enabled

### Requirement: The overview lists the updates that are pending now

The automatic updates overview MUST list, for every app with a policy, the newer version that is available now according to the last availability sweep, and its state: scheduled for the next window, waiting for approval, held by a pin or by the minimum age, or failed earlier. It MUST NOT query every source when the page opens; it MUST read the stored sweep and say when that sweep ran.

#### Scenario: An admin sees what tonight's run will do

- **GIVEN** Dossiq has policy "patches only" and 0.4.50 is available, and Decidiq is pinned on 0.9.3 with 0.10.1 available
- **WHEN** the admin opens the automatic updates tab
- **THEN** Dossiq 0.4.50 MUST be listed as scheduled for the next window
- **AND** Decidiq 0.10.1 MUST be listed as held by the pin

### Requirement: A policy can hold manual installs to its level

A per-app policy MUST carry a switch that holds Versioniq's own install path to the policy's level. With the switch on and the level `patch` or `minor`, a real install of a newer version outside the level MUST be refused with 409 and category `outOfRange`, unless the request carries an explicit range override, which MUST be audited. The switch MUST NOT claim to hold Nextcloud's own updater, and its help text MUST say so. A stored policy without the switch MUST read as off.

#### Scenario: A manual major upgrade is held back

- **GIVEN** `openregister` 2.3.0 has policy `patch` with "Also hold manual installs to this level" on
- **WHEN** an admin picks 3.0.0 in the version picker and clicks Install
- **THEN** the install MUST be refused with category `outOfRange`, naming the patch level
- **AND** confirming the override MUST install 3.0.0 and write a `range_override` audit row

### Requirement: Policies can be kept in a file

`occ versioniq:policies:export` MUST write the kill switch, the window, the default level and every per-app policy to a JSON file with `schemaVersion` 1. `occ versioniq:policies:import <file>` MUST validate the file, report every problem with its JSON path, and apply it through the same stores as the API with one audit row per changed policy; `--dry-run` MUST only print the difference, and `--prune` MUST remove stored per-app policies the file does not name. When `config.php` names a policy file, the nightly job MUST read its policies from that file at every run, MUST do nothing when the file is invalid, the page MUST show the policies read-only with the file's path, and policy writes through the API MUST answer 409.

#### Scenario: An admin reviews a policy change before applying it

- GIVEN an exported policy file in which the admin changed `openregister` from `patch` to `minor`
- WHEN the admin runs `occ versioniq:policies:import policies.json --dry-run`
- THEN the command MUST print that `openregister` goes from patch to minor
- AND no policy MUST change until the admin runs it without `--dry-run`

#### Scenario: A broken file stops automatic updates instead of guessing

- GIVEN `config.php` names a policy file that contains the level `weekly`
- WHEN the nightly job runs inside the window
- THEN it MUST install nothing and log the file error with its JSON path
- AND the Automatic updates section MUST show the error

### Requirement: The nightly job waits until a release is old enough

An admin MUST be able to set a minimum release age in days, from 0 to 90, for the instance, and to override it per app on the app's policy; 0 MUST mean no hold and MUST be the default. A version's age MUST run from its `releasedAt`, or, when the source gives no date, from the first time Versioniq listed it. The automatic update job MUST consider only versions whose age is at least the minimum, and MUST install the newest such version inside the policy level, even when a newer version is still held. A manual install MUST NOT be held.

#### Scenario: A fresh release waits a week

- **GIVEN** `openregister` 2.3.0 is installed with policy `patch`, the minimum age is 7 days, and the App Store lists 2.3.1 released 10 days ago and 2.3.2 released 2 days ago
- **WHEN** the job runs inside the window
- **THEN** it MUST install 2.3.1
- **AND** it MUST NOT install 2.3.2 until 7 days after its release

#### Scenario: A forge release without a date waits from first sight

- **GIVEN** a forge release 1.4.0 without `published_at`, first listed by Versioniq 3 days ago, and a minimum age of 7 days
- **WHEN** the job runs
- **THEN** 1.4.0 MUST be held until 7 days after it was first listed

### Requirement: An admin sees what is held back and when it becomes eligible

A background job MUST work out, every six hours and whether automatic updates are on or not, for every app with a policy other than `none`: the version the next run would install, and every newer qualifying version held back by the minimum age, with the date it becomes eligible. It MUST store the result with the time it was worked out. `GET /api/policies` MUST return each app's plan and that time. The Automatic updates overview MUST show, per app, "Next update" with its version or "No update qualifies", and each held version with its eligibility date, and MUST say when the plan was worked out or that it never was.

#### Scenario: The overview names the held version and its date

- **GIVEN** the situation of "A fresh release waits a week", and the plan job has run
- **WHEN** admin `alice` opens the Automatic updates overview
- **THEN** it MUST show "Next update: 2.3.1" for `openregister`
- **AND** "2.3.2 held back until" the date 7 days after its release, with "minimum age 7 days"

### Requirement: An admin releases one held version early, and the exception clears itself

An admin MUST be able to release one held version of an app early, and to withdraw that, through admin-only, password-confirmed endpoints that are recorded in the audit trail. The job MUST treat that version as old enough. The system MUST remove the exception by itself, and MUST record why in the audit trail, once the app runs that version or a newer one, once the version reaches the minimum age anyway, or once the source no longer lists it.

#### Scenario: A needed fix goes out tonight

- **GIVEN** `openregister` 2.3.2 is held back until next week
- **WHEN** alice picks "Release now" on 2.3.2 in the overview and confirms her password
- **THEN** the next run inside the window MUST install 2.3.2
- **AND** after that install the exception MUST be gone, with an audit row saying it was removed because 2.3.2 is installed

#### Scenario: An exception that is no longer needed disappears

- **GIVEN** an exception for 2.3.2 and the automatic update of 2.3.2 failed, so it stays blocked
- **WHEN** 2.3.2 reaches the minimum age
- **THEN** the exception MUST be removed with an audit row saying the version reached the minimum age

### Requirement: A security fix skips the minimum age

When the stored advisory snapshot says the installed version of an app is inside an advisory's affected range and names a recommended version, the job MUST treat that recommended version as old enough. Newer versions MUST still wait. The policy level and the channel MUST still apply. The audit message and the success notification of such an install MUST say that a security fix was installed before the minimum age, and MUST name the advisories.

#### Scenario: A patch for a known advisory is installed at once

- **GIVEN** `openregister` 2.3.0 is installed with policy `patch` and a minimum age of 7 days, the advisory snapshot says 2.3.0 is affected by GHSA-xxxx-yyyy-zzzz with recommended version 2.3.3, and 2.3.3 was released yesterday
- **WHEN** the job runs inside the window
- **THEN** it MUST install 2.3.3
- **AND** the notification MUST say it is a security fix installed before the minimum age, naming GHSA-xxxx-yyyy-zzzz

#### Scenario: A fix outside the policy level is not installed

- **GIVEN** the same advisory, but the recommended version is 3.0.1 and the policy is `minor`
- **WHEN** the job runs
- **THEN** it MUST NOT install 3.0.1
- **AND** the plan MUST say the fix lies outside the policy level

### Requirement: The nightly job installs only after the attached checks pass

When at least one verdict or command check matches an app, the automatic update job MUST run a dry run of the candidate version with its checks before installing it, and MUST install it only when the dry run and every matching check pass. A failed dry run or check MUST be recorded as a failed attempt for that version, and admins MUST be notified with category `checks_failed` and a hint naming the check and its detail. The existing Retry MUST clear it. When no check matches, the job MUST install as before, without a dry run.

#### Scenario: A failed verdict holds the night's update

- **GIVEN** `openregister` 2.3.0 with policy `patch`, the source offers 2.3.4, and the verdict check "Staging tests" answers 500 for 2.3.4
- **WHEN** the job runs inside the window
- **THEN** 2.3.4 MUST NOT be installed
- **AND** admins MUST be notified that "Staging tests" failed with status 500, and the Automatic updates overview MUST list 2.3.4 as blocked with Retry

#### Scenario: Passing checks let the update through

- **GIVEN** the same app, and the verdict check answers 200 for 2.3.4
- **WHEN** the job runs inside the window
- **THEN** 2.3.4 MUST be installed through the standard installer and the success notification MUST be sent

### Requirement: Proposed updates are worked out ahead of the window and shown

After every availability sweep the system MUST store, for each app with an auto-update policy, the proposed update the policy would install at the next due opening of the window: the installed version, the target version, the kind of step, the planned time and the target version's release notes, or the reason there is none (`pinned`, `blocked`, `not_due`, `limit`, `no_candidate` or `source_error`). It MUST compute them from the version lists the sweep already fetched, without further source calls. `GET /api/auto-update/proposals` MUST be admin-only, MUST return the stored proposals with the time they were computed, and MUST NOT call any source. The Apps tab MUST list the proposed updates in planned order with their release notes.

#### Scenario: An admin sees what installs at the next window

@e2e tests/e2e/proposed-updates.spec.ts

- **GIVEN** `openregister` 2.3.0 on policy `patch`, its source lists 2.3.4, and the availability sweep ran
- **WHEN** an admin opens the Apps tab
- **THEN** the proposed updates list MUST show `openregister` 2.3.0 to 2.3.4, a patch update, with its planned date and release notes
- **AND** it MUST show when the proposals were computed

#### Scenario: Apps without a proposal say why

@e2e tests/e2e/proposed-updates.spec.ts

- **GIVEN** `calendar` on policy `minor` is pinned
- **WHEN** the proposals are computed
- **THEN** `calendar` MUST be listed with the reason `pinned` and no target version

### Requirement: An admin can require approval, or decline a version, before an automatic update installs

An admin MUST be able to set, per app, that an automatic update needs approval (`requireApproval` on the policy). For such an app the job MUST install only a version an admin approved through `POST /api/auto-update/proposals/{appId}/approve` with that exact version. An admin MUST be able to decline a version for any app through `POST /api/auto-update/proposals/{appId}/decline`, after which the job MUST skip that version until the decline is withdrawn through `DELETE /api/auto-update/proposals/{appId}/decline/{version}`. All three routes MUST be admin-only and password-confirmed, and each MUST write an audit entry (`proposal_approve`, `proposal_decline`, `proposal_withdraw`) naming the version.

#### Scenario: An approved version installs, a newer one waits

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `openregister` requires approval and admin `alice` approved 2.3.4
- **WHEN** the job runs inside the window and 2.3.4 is still the version the policy selects
- **THEN** it MUST install 2.3.4
- **AND** when the policy would select 2.3.5 instead, it MUST install nothing until 2.3.5 is approved

#### Scenario: An admin approves on the page

@e2e tests/e2e/proposed-updates.spec.ts

- **GIVEN** a proposal for `openregister` 2.3.4 awaiting approval
- **WHEN** admin `alice` clicks Approve and confirms her password
- **THEN** the proposal MUST read "Approved by alice" with the date
- **AND** the History tab MUST list a `proposal_approve` entry for 2.3.4

#### Scenario: A declined version is skipped

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** an admin declined `deck` 1.9.3 with the reason "breaks our board import"
- **WHEN** the job runs inside the window and 1.9.3 qualifies
- **THEN** it MUST NOT install 1.9.3
- **AND** the proposal list MUST show 1.9.3 as declined with the reason

### Requirement: Planned updates are announced a set number of days ahead with their release notes

An admin MUST be able to set a notice period of 0 to 60 days and, optionally, a Nextcloud group of functional administrators. With a notice period above 0, the system MUST send an `auto_update_planned` notification the first time it proposes a new version, to every admin and every member of that group, naming the app, both versions, the planned date and an excerpt of the release notes. The planned date MUST be the first due opening of the window at least the notice period after the announcement, and the job MUST NOT install the proposal before it. Once announced, a proposal MUST keep its target version until it is installed or no longer valid. Members of the group MUST NOT gain access to any Versioniq endpoint. A security fix whose patch policy handling is `next_window` MUST be announced but MUST NOT wait for the notice period.

#### Scenario: Functional administrators hear two weeks ahead

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** a notice period of 14 days, notice group `functioneel-beheer`, and a new proposal `forms` 4.3.0 to 4.4.0 on 1 October
- **WHEN** the proposal step stores it
- **THEN** every admin and every member of `functioneel-beheer` MUST receive a notification naming 4.4.0, the planned date on or after 15 October, and the release notes excerpt
- **AND** the job MUST NOT install 4.4.0 before that date

#### Scenario: A newer release does not restart the clock

@e2e exclude needs the server clock to move ten days between two sweeps; covered by ProposalServiceTest.

- **GIVEN** `forms` 4.4.0 was announced on 1 October with a 14-day notice period
- **WHEN** 4.4.1 appears on 10 October
- **THEN** the proposal MUST stay 4.4.0 planned for 15 October
- **AND** 4.4.1 MUST become the next proposal after 4.4.0 is installed

### Requirement: Apps in an update group are updated together or not at all

An admin MUST be able to define update groups, each with a name and an ordered list of 2 to 20 apps, with each app in at most one group, through `PUT /api/auto-update/groups` (admin-only, password-confirmed). In a run, the job MUST install a group's due updates in the stored order. The job MUST NOT install any member when a member with a policy is pinned, when its source could not be read, or when its candidate is a version an earlier automatic attempt failed on. When an install in the group fails, the job MUST stop the group and MUST NOT install the members after it. A group run MUST produce one admin notification that lists every member with its outcome.

#### Scenario: A group updates in order

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** a group "Registers" with `openregister` then `opencatalogi`, both on policy `patch`, with 2.3.4 and 1.2.1 available
- **WHEN** the job runs inside the window
- **THEN** it MUST install `openregister` 2.3.4 before `opencatalogi` 1.2.1
- **AND** admins MUST receive one notification naming the group and both outcomes

#### Scenario: A failure stops the group

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** a group of two apps with due updates where the first install fails because its download returns 404
- **WHEN** the job runs inside the window
- **THEN** the second app MUST NOT be installed and MUST be reported as not attempted
- **AND** on the next run the whole group MUST wait until an admin retries the failed version

#### Scenario: A pin on one member holds the group

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `opencatalogi` in group "Registers" is pinned
- **WHEN** the admin opens the Apps tab
- **THEN** the Automatic updates overview MUST show the group as held by the pin on `opencatalogi`
- **AND** the job MUST NOT install `openregister` either

### Requirement: An admin limits how many automatic updates run

An admin MUST be able to set, through the Apps tab and `PUT /api/auto-update/settings`, a maximum number of automatic installs per run (1 to 50) and per seven days (1 to 200), each empty for no limit. The weekly count MUST include every automatic attempt in the last seven days, successful or not. A group MUST count as the number of its members with a candidate, and a group that does not fit in what is left MUST wait whole. Updates a limit held back MUST go first in the next run, and the overview MUST list them as waiting for the limit.

#### Scenario: A per-run limit holds the rest back

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** a per-run limit of 1 and two apps with a due update
- **WHEN** the job runs inside the window
- **THEN** it MUST install one update and hold the other
- **AND** the overview MUST list the held app as waiting for the limit
- **AND** the next run MUST install the held app before any other

#### Scenario: A limit out of range is refused

@e2e exclude covered by tests/unit/Controller/ApiTest.php.

- **GIVEN** an admin enters 0 as the per-run limit
- **WHEN** they save the automatic update settings
- **THEN** the response MUST be 400 and the stored limit MUST NOT change

#### Scenario: An admin sets a limit and a group on the page

@e2e tests/e2e/auto-update.spec.ts

- **GIVEN** an admin on the Apps tab
- **WHEN** they create a group of two apps, set a per-run limit of 3, save and reload
- **THEN** the page MUST show the group with both apps in order and the limit of 3

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

### Requirement: The security level installs only advisory fixes, in the window

For an app on level `security` the nightly job MUST install a version only when the stored advisory snapshot marks the installed version `pinned-to-vulnerable`, names a `recommendedVersion` newer than the installed one with the same major, and the bound source lists that version. The job MUST install exactly that version through the standard installer, inside the window. It MUST NOT act when the snapshot is missing or older than twice the advisory check interval, MUST skip pinned apps as for every level, and MUST show on the Automatic updates overview why an affected `security` app was not updated (`no_fix`, `needs_major`, `not_in_source` or `stale`).

#### Scenario: An affected app gets its fix

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `openregister` 2.3.0 on level `security`, a fresh snapshot that marks 2.3.0 affected with recommended version 2.3.5, and a source listing 2.3.5 and 2.4.0
- **WHEN** the job runs inside the window
- **THEN** it MUST install 2.3.5 and not 2.4.0

#### Scenario: An unaffected app is left alone

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `calendar` on level `security` with 4.7.1 available and no advisory affecting its installed version
- **WHEN** the job runs inside the window
- **THEN** it MUST NOT install anything for `calendar`

#### Scenario: A fix behind a major is explained, not installed

@e2e exclude the forge fixture builds no release on a second major line; covered by SecurityCandidateTest and AutoUpdateOverview.spec.ts.

- **GIVEN** `deck` 1.9.2 on level `security`, affected, with recommended version 2.0.1
- **WHEN** the admin opens the Apps tab after the next run
- **THEN** the overview MUST say the fix for `deck` needs a major update and was not installed

### Requirement: An admin records a patch policy and security fixes go first

An admin MUST be able to record on the Settings tab, through `PUT /api/patch-policy` (admin-only, password-confirmed), a patch policy with, per advisory severity (`critical`, `high`, `medium`, `low`, `unknown`), a deadline of 1 to 365 days or none and a handling (`next_window`, `scheduled` or `manual`), per regular kind of update (`patch`, `minor`, `major`) a handling (`automatic` or `manual`), and a free text statement of at most 4000 characters. The page MUST show the policy as sentences generated from the stored values, with who changed it last and when, and MUST offer those sentences as a text download. Every change MUST write an audit entry with operation `patch_policy`. In each run the job MUST install fixes for affected apps whose policy is not `none` before any other update; a severity with handling `next_window` MUST NOT be held by a kind's schedule or a run limit, and a severity with handling `manual` MUST NOT be installed automatically. A regular kind with handling `manual` MUST NOT be installed automatically for any app. With no stored patch policy, the job MUST behave as it did before this requirement.

#### Scenario: An admin records the policy

@e2e tests/e2e/patch-policy.spec.ts

- **GIVEN** admin `alice` on the Settings tab
- **WHEN** she sets critical fixes to "at the next update window" with a deadline of 3 days, and major updates to "by hand", and saves
- **THEN** the page MUST show "Critical security fixes: installed at the next update window. Deadline: 3 days." and "Major updates: installed by hand only."
- **AND** it MUST show that alice changed the policy, with the date
- **AND** the History tab MUST list a `patch_policy` entry by alice

#### Scenario: A critical fix does not wait for its kind's day

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** critical fixes are set to `next_window`, minor updates do not run today, and `openregister` on level `minor` is affected by a critical advisory fixed in 2.4.0
- **WHEN** the job runs inside the window today
- **THEN** it MUST install 2.4.0 before any other update in that run

#### Scenario: A by-hand kind is never automatic

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** patch updates are set to `manual` and `deck` 1.9.2 on level `patch` has 1.9.3 available and no advisory
- **WHEN** the job runs inside the window
- **THEN** it MUST NOT install 1.9.3
- **AND** the overview MUST say that patch updates are set to by hand in the patch policy
