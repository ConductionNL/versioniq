# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [advisories-triage](../../)

## ADDED Requirements

### Requirement: An admin dismisses an advisory with a recorded reason

An admin MUST be able to dismiss one stored advisory for one app, or for the server, through `POST /api/advisory-triage/dismiss` (admin-only, password-confirmed), with a reason from `not_used`, `inaccurate`, `tolerable_risk`, `mitigated` or `fix_planned`, an optional comment of at most 1000 characters, and an optional end date that `fix_planned` requires. While a dismissal is active, the advisory MUST NOT count toward the app's advisory state, its badge severity, notifications, the weekly digest or automatic security installs, and it MUST stay listed under Dismissed with the reason, comment, admin, time and end date. A dismissal MUST end on its end date and when the app's installed version changes. An admin MUST be able to reopen it through `POST /api/advisory-triage/reopen`. Every dismissal and reopen MUST write an audit entry. Dismissals MUST be applied when the snapshot is read, never written into it.

#### Scenario: A dismissed advisory stops counting but stays visible

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** the last sweep stored one server advisory that affects the installed version
- **WHEN** admin `alice` dismisses it on the Advisories tab as "not used" with the comment "Public shares are off" and confirms her password
- **THEN** the server block MUST no longer say the version is affected
- **AND** the advisory MUST be listed under Dismissed with the reason, the comment, alice and the date
- **AND** the History tab MUST list an `advisory_dismiss` entry by alice

#### Scenario: An update ends the dismissal

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** an advisory on `openregister` 2.3.0 was dismissed
- **WHEN** `openregister` is updated to 2.3.2, which the advisory still affects
- **THEN** the advisory MUST count again and the card MUST show it

#### Scenario: A planned fix needs an end date

@e2e exclude covered by AdvisoryTriageControllerTest.

- **GIVEN** an admin dismisses an advisory with reason `fix_planned` and no end date
- **WHEN** the request reaches `POST /api/advisory-triage/dismiss`
- **THEN** the response MUST be 400 and nothing MUST be stored

### Requirement: An admin assigns an advisory to an owner

An admin MUST be able to assign a stored advisory to a user in the admin group or to a Nextcloud group through `POST /api/advisory-triage/assign` (admin-only, password-confirmed), and to remove the assignment. For a group, the owners MUST be the group's members who are admins. Assigning MUST notify the owners with the app, the advisory and who assigned it, and MUST write an audit entry. The Advisories tab MUST show each advisory's owner and MUST offer a filter for advisories assigned to the current admin, directly or through a group.

#### Scenario: An admin hands an advisory to a colleague

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** admins `alice` and `bob`, and an advisory affecting `openregister`
- **WHEN** alice assigns it to bob
- **THEN** bob MUST receive a notification naming `openregister`, the advisory and alice
- **AND** bob MUST see it when he filters the Advisories tab on "Assigned to me"

#### Scenario: A non-admin cannot be the owner

@e2e exclude covered by AdvisoryTriageControllerTest.

- **GIVEN** `carol` is not an admin
- **WHEN** an admin assigns an advisory to `user:carol`
- **THEN** the response MUST be 400 and the advisory MUST stay unassigned
