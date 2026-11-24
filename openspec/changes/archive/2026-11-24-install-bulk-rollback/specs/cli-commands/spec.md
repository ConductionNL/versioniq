# cli-commands Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-bulk-rollback](../../)

## ADDED Requirements

### Requirement: Take snapshots and roll back from the CLI

`occ versioniq:snapshot` MUST take a snapshot, with an optional `--name`. `occ versioniq:snapshots` MUST list them, as a table or with `--json`. `occ versioniq:rollback` MUST take a snapshot id or `--to=<YYYY-MM-DD>`, print the plan, and ask for confirmation unless `--yes` is given. `--dry-run` MUST run the check without changing anything, `--move-pins` MUST include pinned steps, and `--continue` MUST go on after a failure. The command MUST take a snapshot before it installs, MUST record the start and end of the run in the audit trail, and MUST exit 0 when every step succeeded, else with the `versioniq:install` exit code of the first failed step.

#### Scenario: An operator undoes a night of updates over SSH

- **GIVEN** a `before_auto_update` snapshot from last night
- **WHEN** an admin runs `occ versioniq:rollback --to=2026-09-26 --yes`
- **THEN** every downgrade step MUST be installed through the standard installer and the exit code MUST be 0

#### Scenario: A dry run changes nothing

- **WHEN** an admin runs `occ versioniq:rollback <id> --dry-run`
- **THEN** stdout MUST list each step with the migrations it would orphan
- **AND** no app version MUST change
