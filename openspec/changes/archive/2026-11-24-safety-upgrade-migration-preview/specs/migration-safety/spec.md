# migration-safety Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [safety-upgrade-migration-preview](../../)

## ADDED Requirements

### Requirement: A dry run of an upgrade previews the database changes

For an upgrade, a dry run MUST list the target's migration steps that Nextcloud has not recorded as run for the app, reading the recorded steps from the database and falling back to a file comparison marked as an estimate when it cannot. For each step it MUST list the actions the step declares with Nextcloud's migration attributes, or else the tables it names, and MUST say when the step also moves data. For each existing table a step changes it MUST give the current row count and a size class (`new`, `small` under 100,000 rows, `large` up to 1,000,000, `very_large` above), and MUST NOT state a duration in seconds. It MUST list the repair steps the target runs after its migrations. The dry run MUST NOT load the target's classes or change the database. The picker MUST offer "Preview database changes" on a selected upgrade, and `occ versioniq:install --dry-run` MUST print the same preview and carry it in `--json`.

#### Scenario: An admin sees a large table change before the upgrade

- **GIVEN** `openregister` 2.3.0 is installed, 2.4.0 ships a step declaring an added index on `openregister_objects`, and that table holds 4,200,000 rows
- **WHEN** admin `alice` selects 2.4.0 and picks "Preview database changes"
- **THEN** the panel MUST list that step with "adds an index" on `openregister_objects`, 4,200,000 rows, and "may take long; plan a maintenance window"
- **AND** no migration MUST have run

#### Scenario: A step that already ran is not listed

- **GIVEN** 2.4.0 ships steps A and B, and Nextcloud recorded A as run during an earlier install of 2.4.0 that was later downgraded
- **WHEN** the preview is built
- **THEN** only B MUST be listed

#### Scenario: An upgrade without schema steps

- **GIVEN** 2.3.1 ships the same migration steps as 2.3.0
- **WHEN** alice previews the upgrade to 2.3.1
- **THEN** the panel MUST say there are no database changes, and list any repair steps

#### Scenario: A script reads the preview

- **WHEN** an admin runs `occ versioniq:install openregister 2.4.0 --dry-run --json`
- **THEN** stdout MUST carry `migrationPreview` with the steps, their tables and size classes
