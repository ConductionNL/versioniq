---
status: implemented
---

# Auto-Update Policies Specification

**Status**: implemented
**Standards**: OCP\BackgroundJob\TimedJob, OCP\IAppConfig, OCP\Notification\IManager, Semantic Versioning 2.0.0 (level comparison)
**Feature tier**: MVP

## Purpose

Turn Versioniq from a repair tool into bounded, observable update automation: per-app semver-level policies executed nightly through the app's own verified installer path, honoring pins, constrained to a maintenance window, defaulting to off, and reporting every action. Explicitly not a silent `occ app:update --all` clone — the value is the bounds and the reporting.

## Requirements

### Requirement: Per-app update policy [MVP]

Admins MUST be able to set, read, and clear a per-app policy `level` ∈ `none|patch|minor|all` via `GET /api/policies`, `PUT /api/app/{appId}/policy`, `DELETE /api/app/{appId}/policy`; writes MUST require password confirmation and MUST record `setBy`/`setAt`. Policy MUST be persisted as `policy.{appId}` app config JSON. Absent policy means `none`. Non-admins MUST receive 403.

#### Scenario: Set a patch policy

@e2e tests/e2e/auto-update.spec.ts

- WHEN admin `alice` calls `PUT /api/app/openregister/policy` with `{level: "patch"}` and confirms her password
- THEN `policy.openregister` MUST record level patch, setBy alice, setAt ISO-8601
- AND `GET /api/policies` MUST list it

#### Scenario: Invalid level rejected

@e2e tests/e2e/install-effects.spec.ts

- WHEN `PUT .../policy` is called with `{level: "yolo"}`
- THEN the response MUST be 400 and no policy MUST be written

---

### Requirement: Nightly policy execution through the standard installer [MVP]

A `TimedJob` that wakes every 15 minutes MUST, once per opening of the window, when `auto_update_enabled` is true and the current time in Nextcloud's `default_timezone` (UTC when unset or invalid) is inside `auto_update_window` (default `01:00-05:00`): for every app with policy level ≠ none — skip pinned apps entirely; list versions from the app's bound source; select the highest available version that is (a) strictly newer than installed, (b) compatible, (c) within the policy level (patch: same major.minor; minor: same major; all: any); and install it via `InstallerService::installAppVersion` with all standard verification, backup/restore, and outcome classification. The job MUST never downgrade, MUST attempt a given (appId, version) at most once (recording attempts), and MUST proceed to the next app after any failure.

#### Scenario: Patch-level update applied

@e2e exclude the nightly AutoUpdateJob cannot be driven within a browser run; candidate selection is unit-tested.

- GIVEN `openregister` installed at 2.3.0, policy patch, source lists 2.3.4 and 2.4.0
- WHEN the job runs inside the window
- THEN 2.3.4 MUST be installed via the standard installer path
- AND 2.4.0 MUST NOT be considered

#### Scenario: Pinned app skipped

@e2e exclude the job's pin-skip is unit-tested.

- GIVEN `openregister` has any policy and a pin
- WHEN the job runs
- THEN `openregister` MUST be skipped without a source query

#### Scenario: Failed attempt is not retried

@e2e exclude the job's attempt ledger is unit-tested.

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

@e2e exclude the job's window/kill-switch gate is unit-tested.

- GIVEN `auto_update_enabled` false, or a run at 13:00 with window `01:00-05:00`
- WHEN the job fires
- THEN no source queries and no installs MUST happen

---

### Requirement: Every auto-update outcome is reported [MVP]

Each attempted install MUST produce an admin notification: success (app, old → new version) or failure (app, target version, classified category + hint). Notifications MUST use the existing Notifier infrastructure. A job run that updates nothing MUST NOT notify.

#### Scenario: Success notification

@e2e exclude the job's success notification is unit-tested.

- GIVEN the job updates `openregister` 2.3.0 → 2.3.4
- THEN admins MUST receive a notification naming the app and both versions

#### Scenario: Failure notification carries the classification

@e2e exclude the job's failure notification is unit-tested.

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
