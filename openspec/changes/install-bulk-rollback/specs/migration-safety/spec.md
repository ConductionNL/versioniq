# migration-safety Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-bulk-rollback](../../)

## ADDED Requirements

### Requirement: Versioniq keeps snapshots of every app's version

The system MUST take a snapshot of every app it manages, recording per app the installed version, the bound source and whether it is enabled, with the time, the actor and the reason. It MUST take one every day, one before the automatic update job installs the first app of a sweep, one before a bulk rollback runs, and one whenever an admin asks, optionally with a name. It MUST keep the newest 60 unnamed snapshots and up to 20 named ones, and a named one until an admin deletes it. Taking a snapshot MUST be recorded in the audit trail. Snapshot endpoints MUST be admin-only, and writes MUST require password confirmation.

#### Scenario: A snapshot precedes the night's updates

- **GIVEN** the automatic update job is about to install `openregister` 2.3.4 as the first update of tonight's sweep
- **WHEN** the job runs
- **THEN** a snapshot with reason `before_auto_update` MUST be stored before the install starts, recording `openregister` 2.3.0

#### Scenario: An admin names a snapshot before a risky change

- **WHEN** admin `alice` takes a snapshot named "Before the October upgrade" from the History tab and confirms her password
- **THEN** it MUST be listed with that name and kept until she deletes it

### Requirement: An admin rolls several apps back to a snapshot or a date

An admin MUST be able to pick a snapshot, or a date that selects the newest snapshot taken on or before it, and see a plan with one step per app: unchanged, downgrade, upgrade, pinned elsewhere, removed since, or added since. A check MUST run a dry-run install for every downgrade step and show the migrations it would orphan, without changing anything. A run MUST take a snapshot first, ask for the password once, and install the downgrade and upgrade steps one app at a time through the standard install path, from the snapshot's source, newest change first. Pinned steps MUST be skipped unless the admin chose to move the pins. Removed and added apps MUST be left alone. A run MUST stop at the first failure and offer to continue with the rest. The start and the end of a run MUST be recorded in the audit trail with the snapshot and the counts of steps that succeeded, failed and were skipped.

#### Scenario: Last night's updates are undone

- **GIVEN** last night's sweep moved `openregister` from 2.3.0 to 2.3.4 and `opencatalogi` from 1.8.0 to 1.8.2, after a `before_auto_update` snapshot
- **WHEN** alice picks that snapshot on the History tab, runs the check, and runs the plan
- **THEN** the page MUST install `opencatalogi` 1.8.0 and then `openregister` 2.3.0 through the standard installer, each with its own audit row
- **AND** the trail MUST hold a `bulk_rollback` row at the start and one at the end saying 2 steps succeeded

#### Scenario: A failure stops the run

- **GIVEN** a plan with three downgrade steps where the second fails its checksum
- **WHEN** alice runs it
- **THEN** the first app MUST be rolled back, the second MUST be reverted to what it ran before the step, the third MUST NOT be touched
- **AND** the dialog MUST offer "Continue with the rest"

#### Scenario: Rolling back to a date

- **GIVEN** snapshots taken on 20 and 25 September
- **WHEN** alice asks for the state of 23 September
- **THEN** the plan MUST be built from the snapshot of 20 September and MUST say so
