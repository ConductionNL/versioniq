# safety-upgrade-migration-preview tasks

## 1. Reading the steps

- [ ] 1.1 Confirm on a live Nextcloud 32 instance how `oc_migrations` records an app step (the `version` value against the class name), then add `MigrationDiffer::pending()` with the file fallback (design D1). Verify: `tests/unit/Service/Installer/MigrationDifferTest.php` covers steps recorded and not, a step that ran and ships again, an unreadable table (estimate), and an unreadable target (null).
- [ ] 1.2 Confirm the attribute classes in `OCP\Migration\Attributes` of `nextcloud/ocp` stable32, then add `lib/Service/Installer/MigrationAttributeReader.php` (design D2). Verify: `tests/unit/Service/Installer/MigrationAttributeReaderTest.php` with fabricated step files: each attribute, several on one step, an unknown attribute, the `changeSchema()` fallback, a data step, and a step that describes nothing.
- [ ] 1.3 Add `lib/Service/Installer/TableSizeProbe.php` (design D3). Verify: `tests/unit/Service/Installer/TableSizeProbeTest.php` covers each size class, a missing table (`new`), a name that fails the pattern (not queried), the 20-table cap, and a failing count ("size unknown").

## 2. The dry run

- [ ] 2.1 Add `lib/Service/Installer/MigrationPreview.php` and call it for an upgrade dry run in both installers (design D4). Verify: `tests/unit/Service/InstallerDryRunSideEffectsTest.php` asserts `migrationPreview` on an upgrade dry run of each installer kind, its absence on a real install and on a downgrade, and no database write.

## 3. Page and CLI

- [ ] 3.1 Add "Preview database changes" and `src/components/MigrationPreviewPanel.vue`, with the texts in `src/utils/migrationSafety.ts` (design D5). Verify: `src/components/MigrationPreviewPanel.spec.ts` covers each size class, a data step, repair steps, an estimate, and no changes; `src/utils/migrationSafety.spec.ts` covers the advice texts.
- [ ] 3.2 Print the preview in `lib/Command/InstallVersion.php` after a dry run. Verify: `tests/unit/Command/InstallVersionTest.php` covers the table output and the JSON key.
- [ ] 3.3 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.4 Extend `tests/e2e/version-management.spec.ts`: add a migration step to one release in `tests/e2e/fixtures/forge/build-artifacts.sh`, then preview the upgrade to it and assert the step and its table in the panel.

## 4. Close

- [ ] 4.1 Set the matrix row `saf-migration-preview` to `built` with an evidence line, then archive this change.
