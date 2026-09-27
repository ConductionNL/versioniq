# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [releases-minimum-age](../../)

## ADDED Requirements

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
