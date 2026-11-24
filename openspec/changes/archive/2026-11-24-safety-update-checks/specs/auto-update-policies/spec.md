# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [safety-update-checks](../../)

## ADDED Requirements

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
