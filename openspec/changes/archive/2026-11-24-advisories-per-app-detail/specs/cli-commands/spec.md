# cli-commands Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [advisories-per-app-detail](../../)

## ADDED Requirements

### Requirement: Read advisories and the compliance status from the CLI

`occ versioniq:advisories` MUST print, from the stored advisory snapshot and without calling any source, the compliance status and per target the advisories affecting the installed version and the recent ones, as a table, or as JSON with `--json`. `--app` MUST limit the output to one app, or to the server with `:server`. With `--check` the command MUST exit 0 when the status is compliant, 1 when it is not compliant and 2 when it is unknown. Without `--check` it MUST exit 0 whenever it printed.

#### Scenario: A monitoring script checks compliance

@e2e tests/e2e/cli.spec.ts

- **GIVEN** a fresh advisory check found no installed version affected and reached every app
- **WHEN** a script runs `occ versioniq:advisories --check`
- **THEN** the exit code MUST be 0 and the output MUST start with the compliant status

#### Scenario: Never checked is not success

@e2e tests/e2e/cli.spec.ts

- **GIVEN** no advisory check has completed
- **WHEN** a script runs `occ versioniq:advisories --check`
- **THEN** the exit code MUST be 2
