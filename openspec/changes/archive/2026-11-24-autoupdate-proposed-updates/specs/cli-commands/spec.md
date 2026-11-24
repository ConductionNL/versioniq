# cli-commands Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [autoupdate-proposed-updates](../../)

## ADDED Requirements

### Requirement: Dry-run the automatic update policy from the CLI

`occ versioniq:auto-update --dry-run` MUST plan the next run of the automatic update policy live against the bound sources, applying pins, the attempt ledger, schedules, limits, notice, approval and declines, and MUST print for every app with a policy the version it would install or the reason it would not. It MUST NOT install anything, MUST NOT write the attempt ledger and MUST NOT send any notification. `--json` MUST print the same plan as JSON. The command MUST exit 0 when it printed a plan and automatic updates are on, and 1 when automatic updates are off, while still printing the plan.

#### Scenario: A script previews tonight's run

@e2e tests/e2e/cli.spec.ts

- **GIVEN** `openregister` 2.3.0 on policy `patch` and the fixture forge serving 2.3.4
- **WHEN** an admin runs `occ versioniq:auto-update --dry-run --json`
- **THEN** stdout MUST be valid JSON with an entry for `openregister` naming 2.3.0 and 2.3.4
- **AND** `openregister` MUST still be at 2.3.0 afterwards

#### Scenario: Automation off is not reported as nothing to do

@e2e tests/e2e/cli.spec.ts

- **GIVEN** automatic updates are off
- **WHEN** an admin runs `occ versioniq:auto-update --dry-run`
- **THEN** the command MUST print the plan under a line saying automatic updates are off
- **AND** it MUST exit 1
