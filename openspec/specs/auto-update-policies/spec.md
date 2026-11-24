---
status: implemented
---

# Auto-Update Policies Specification

**Status**: implemented
**OpenSpec changes**: [releases-minimum-age](../../changes/archive/2026-11-24-releases-minimum-age/), [safety-update-checks](../../changes/archive/2026-11-24-safety-update-checks/)
**Standards**: OCP\BackgroundJob\TimedJob, OCP\IAppConfig, OCP\Notification\IManager, Semantic Versioning 2.0.0 (level comparison)
**Feature tier**: MVP

## Purpose

Turn Versioniq from a repair tool into bounded, observable update automation: per-app semver-level policies executed nightly through the app's own verified installer path, honoring pins, constrained to a maintenance window, defaulting to off, and reporting every action. Explicitly not a silent `occ app:update --all` clone — the value is the bounds and the reporting.

## Requirements

### Requirement: Per-app update policy [MVP]

Admins MUST be able to set, read, and clear a per-app policy `level` ∈ `none|patch|minor|all` via `GET /api/policies`, `PUT /api/app/{appId}/policy`, `DELETE /api/app/{appId}/policy`; writes MUST require password confirmation and MUST record `setBy`/`setAt`. Policy MUST be persisted as `policy.{appId}` app config JSON. Absent policy means the instance default level, which is `none` unless an admin set another. `GET /api/policies` MUST return, per app, whether its level is its own or the default. Non-admins MUST receive 403.

#### Scenario: Set a patch policy

@e2e tests/e2e/auto-update.spec.ts

- WHEN admin `alice` calls `PUT /api/app/openregister/policy` with `{level: "patch"}` and confirms her password
- THEN `policy.openregister` MUST record level patch, setBy alice, setAt ISO-8601
- AND `GET /api/policies` MUST list it

#### Scenario: Invalid level rejected

@e2e tests/e2e/install-effects.spec.ts

- WHEN `PUT .../policy` is called with `{level: "yolo"}`
- THEN the response MUST be 400 and no policy MUST be written

#### Scenario: An app without a policy follows the default

- GIVEN the instance default level is `patch` and `calendar` has no stored policy
- WHEN the admin opens the Apps tab
- THEN the `calendar` card MUST show level patch marked as the default
- AND the nightly job MUST treat `calendar` as a patch policy

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

`auto_update_enabled` (default `false`) and `auto_update_window` (default `01:00-05:00`, format `HH:MM-HH:MM`, windows crossing midnight supported) MUST be admin-configurable via the settings UI and readable via the API. With the switch off, per-app policies remain stored but inert, and the UI MUST say so.

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
