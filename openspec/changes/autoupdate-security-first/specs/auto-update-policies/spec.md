# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [autoupdate-security-first](../../)

## MODIFIED Requirements

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

## ADDED Requirements

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
