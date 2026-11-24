# Design: safety-upgrade-migration-preview

Read against `development` at 02e1050 (2026-09-27).

## Context

- `MigrationDiffer::diff()` (`lib/Service/Installer/MigrationDiffer.php:43-58`) lists the `lib/Migration/Version*.php` basenames in the installed copy that the target lacks, read from the file system (`:63-83`), and returns null when either side cannot be read. Its docblock explains why it reads files and not the `migrations` table: for a downgrade the file diff is symmetric with the target.
- Both installers call it only for a downgrade, after unpacking the target and before any swap: the signed one at `lib/Service/SelectedReleaseInstallerService.php:648-651`, the external one at `lib/Service/ExternalReleaseInstallerService.php:252-257`. The dry-run result carries `orphanedMigrations` only then.
- The page asks for that diff with a dry run before the downgrade dialog opens (`fetchDowngradePreview()`, `src/App.vue:1467-1471`), and the dialog lists the steps (`src/dialogs/DowngradeConfirmDialog.vue`). The texts live in `orphanedMigrationsSummary()` (`src/utils/migrationSafety.ts`).
- `InstallFinalizer::finalize()` runs `MigrationService::migrate('latest')` and then the target's `post-migration` repair steps (`lib/Service/Installer/InstallFinalizer.php:90-102`). So the steps an upgrade runs are the target's migrations Nextcloud has not recorded as run, followed by those repair steps.
- The target's migration classes cannot be loaded in a dry run: they share their class names with the installed version's classes, which the running process may already have loaded.

## Goals and non-goals

Goals: before an upgrade, the admin sees which migration steps will run, what they change, how large the touched tables are, and which repair steps follow.

Non-goals: running or timing migrations; the server's own migrations; the downgrade diff.

## Decisions

### D1. Which steps will run

`MigrationDiffer::pending(appId, targetPath)` lists the target's `lib/Migration/Version*.php` steps whose version Nextcloud has not recorded as run for the app. It reads the recorded versions from the `migrations` table (`app`, `version` columns) through `IDBConnection`, read-only, and compares them with each class name minus its `Version` prefix. When the table cannot be read it falls back to the file diff in the other direction (target steps the installed copy lacks) and says the list is an estimate.

For an upgrade this answers from the database, unlike the downgrade diff. A step that ran once, was orphaned by a downgrade and ships again will not run again; only the table knows that. Task 1.1 confirms the recorded version format against a live instance before the comparison is written.

Alternative considered: reuse the file diff for upgrades. Rejected as the main path for the case above, kept as the fallback.

### D2. What each step does

`MigrationAttributeReader::read(file)` reads the step's source as text and never loads it:

- the attributes in `OCP\Migration\Attributes` that Nextcloud added in version 30 to describe a migration, such as a created table, an added column or index, a dropped table, a changed column, or a data migration. The reader takes each attribute's short class name and its `table`, `name` and `description` arguments. Task 1.2 confirms the class list in `nextcloud/ocp` for stable32 before the reader is written;
- when a step has none, the table names passed to `createTable()`, `getTable()`, `dropTable()` and `hasTable()` in `changeSchema()`;
- `dataStep: true` when `postSchemaChange()` has a body, since that is where a step moves data.

A step that yields nothing reads "This step does not describe what it changes."

### D3. How large the touched tables are

`TableSizeProbe::rows(table)` counts the rows of each table a step changes that exists now: `SELECT COUNT(*)` through the query builder, with the configured table prefix, only for names matching `^[a-z0-9_]+$`, and at most 20 tables per preview. The result becomes a size class:

| Class | Rows | Advice |
|---|---|---|
| `new` | table does not exist yet | fast |
| `small` | under 100,000 | seconds |
| `large` | 100,000 to 1,000,000 | may take minutes |
| `very_large` | above 1,000,000 | may take long; plan a maintenance window |

A `dataStep` adds "Moves data; the time depends on the amount of data." A count that fails reads "size unknown".

Alternative considered: an estimate in seconds. Rejected. The time depends on the database engine, its version, indexes, disk and load; a number would be a guess presented as a fact.

### D4. The preview in the dry run

For an upgrade, both installers call `MigrationPreview::build(appId, installedPath, targetPath, targetInfo)` where they call `diff()` for a downgrade, and add `migrationPreview` to the dry-run result: `{steps: [{name, actions, tables: [{name, rows, sizeClass}], dataStep}], repairSteps: [...], estimated: bool}` or null when the target cannot be read. `repairSteps` are the class names in the target `info.xml` under `repair-steps/post-migration`. A real install does not build the preview.

### D5. The page and the CLI

The picker shows "Preview database changes" on a selected upgrade. It runs a dry run with `requestInstall(..., forceDryRun)` like `fetchDowngradePreview()` and renders `MigrationPreviewPanel.vue`: each step, its actions, each table with rows and advice, the data steps, and the repair steps; "No database changes" when the list is empty. `occ versioniq:install --dry-run` prints the same list after its outcome, and `--json` carries `migrationPreview`.

## Risks and trade-offs

- [`COUNT(*)` on a very large table is slow on some engines] → the probe runs only in a dry run the admin asked for, caps the number of tables, and a slow count is still faster than the migration it warns about.
- [Attribute names differ between Nextcloud releases] → the reader uses short class names and shows an unknown attribute by its name, rather than dropping it.
- [The text scan misses a table name built at runtime] → such a step reads "does not describe what it changes", and the table list is marked incomplete.

## Migration

Nothing is stored and no schema changes. `migrationPreview` is a new, optional key in the dry-run payload.
