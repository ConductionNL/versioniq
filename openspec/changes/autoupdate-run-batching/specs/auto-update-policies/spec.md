# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [autoupdate-run-batching](../../)

## ADDED Requirements

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
