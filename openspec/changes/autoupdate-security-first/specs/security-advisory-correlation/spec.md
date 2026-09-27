# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [autoupdate-security-first](../../)

## MODIFIED Requirements

### Requirement: The admin is notified and stays in control

The system MUST be able to notify an administrator (via the Nextcloud notification API) when
a newly-published advisory affects an installed or pinned version. Finding an advisory MUST NOT
change any version by itself: the advisory refresh and the notification path MUST stay
read-only. A version MAY change because of an advisory only when an admin set, before the
advisory was found, a policy that allows it: the app's auto-update level other than `none`,
together with the patch policy's handling for the advisory's severity. Such a change MUST happen
only in the nightly automatic update job, inside the update window, through the standard
installer, and MUST be notified and audited like any automatic update. The system MUST NOT
auto-unpin, and a pinned app MUST NOT be changed because of an advisory. Without such a
policy, the system surfaces the advisory and the recommended safe version, and the
administrator decides.

#### Scenario: A new advisory affecting a pinned version notifies the admin

- **GIVEN** an app pinned to a version, and a newly-published advisory affecting that version
- **WHEN** the scheduled advisory refresh runs
- **THEN** an admin notification MUST be raised naming the app, version, and advisory
- **AND** no version change MUST occur automatically

@e2e exclude notify-on-new-advisory covered by the refresh-job unit test; no auto-change asserted (job performs no install/pin mutation).

#### Scenario: An app without a policy is never changed by an advisory

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `calendar` has no auto-update policy and the advisory refresh finds its installed version affected
- **WHEN** the refresh runs and the nightly window passes
- **THEN** `calendar` MUST keep its installed version
- **AND** admins MUST be notified with the recommended version

#### Scenario: A policy set in advance lets the fix install in the window

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `openregister` is on level `security` and the refresh finds 2.3.0 affected with recommended version 2.3.5
- **WHEN** the refresh runs at 14:00
- **THEN** no version MUST change at 14:00
- **AND** the next nightly run inside the window MUST install 2.3.5 through the standard installer and notify the admins
