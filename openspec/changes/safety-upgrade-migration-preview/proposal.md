---
kind: code
---

# Proposal: safety-upgrade-migration-preview

## Why

An upgrade that adds a column to a table with ten million rows can hold an instance in maintenance mode for a long time. Nextcloud gives the admin no warning, and neither does Versioniq. Before a downgrade Versioniq lists the migrations the target lacks. Before an upgrade it says nothing about the migrations that will run, what they change, or how large the tables they touch are. An admin who wants to plan an update window has to read the app's source.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `saf-migration-preview` | partial, built | The missing half: the dry run lists the migrations a downgrade would orphan, but there is no preview of the migrations an upgrade will run, and no estimate of how long they may take. |

### Demand

- `saf-migration-preview`: roadmap, https://github.com/nextcloud/server/issues/45943. The matrix records Nextcloud's `occ migrations:preview` as the delivered part of that issue; its app store and admin UI parts are still open work packages.

### Competitors rated yes (evidence quoted from the matrix)

No competitor is rated yes. Nextcloud is partial: "occ migrations:preview <version> lists server and shipped-app migrations from release metadata (core/Command/Db/Migrations/PreviewCommand.php:38-70) and names apps without metadata; the app store and admin UI parts are still open work packages on the issue."

## What changes

- A dry run of an upgrade lists the database migration steps the target ships and this instance has not run yet.
- Each step says what it does where the app declares it, with the migration attributes Nextcloud added in version 30 (create a table, add a column or an index, and so on). A step without them is scanned for the tables it names. A step that also moves data is marked as such.
- Each table a step changes shows how many rows it holds now and a size class: new table, small, large, or very large. The page and the CLI turn that into plain advice, such as "May take minutes; plan a maintenance window". There is no promise in seconds.
- The repair steps the target runs after its migrations are listed by name.
- The picker offers "Preview database changes" for a selected upgrade, and `occ versioniq:install --dry-run` prints the same list, in JSON too.

## Scope

In scope: the list of steps to run, the attribute reader and the fallback scan, the row counts and size classes, the repair steps, the picker panel, the CLI output, tests.

Out of scope:
- Running migrations in a dry run, or timing them on a copy of the database. That needs a second database and is not what a dry run is.
- The server's own migrations. Nextcloud's `occ migrations:preview` covers them.
- Changing the downgrade diff. It stays as `migration-safety` specifies it.

## Impact

- New: `lib/Service/Installer/MigrationPreview.php`, `lib/Service/Installer/MigrationAttributeReader.php`, `lib/Service/Installer/TableSizeProbe.php`, `src/components/MigrationPreviewPanel.vue`.
- Changed: `lib/Service/Installer/MigrationDiffer.php` (steps to run), `lib/Service/SelectedReleaseInstallerService.php` and `lib/Service/ExternalReleaseInstallerService.php` (the preview in an upgrade dry run), `lib/Command/InstallVersion.php` (output), `src/App.vue` (the button), `src/utils/migrationSafety.ts` (texts), `l10n/en` and `l10n/nl`.
- ADDED requirement in `migration-safety`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the preview is a read-only dry run it can offer.

## Rollback

Revert the change. Nothing is stored: the preview is computed in each dry run.
