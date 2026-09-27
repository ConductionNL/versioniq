# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [autoupdate-proposed-updates](../../)

## ADDED Requirements

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
