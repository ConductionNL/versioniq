# inventory-pending-updates tasks

## 1. Sweep and snapshot

- [ ] 1.1 Add `lib/Service/Availability/AvailabilityService.php` with `sweep(float $budgetSeconds)` returning the per-app fields of design D2. Verify: `tests/unit/Service/Availability/AvailabilityServiceTest.php` covers a newer compatible version, a newer incompatible one, a pre-release, a pinned app, a source error, and the release-line count.
- [ ] 1.2 Add `lib/Service/Availability/AvailabilityResultStore.php` on the `AdvisoryResultStore` pattern. Verify: a unit test that an unencodable snapshot keeps the previous one and that "never swept" reads `checkedAt: null`.
- [ ] 1.3 Add `lib/BackgroundJob/AvailabilityRefreshJob.php` (6 hours, 600 s budget) and register it in `appinfo/info.xml`. Verify: `tests/unit/BackgroundJob/AvailabilityRefreshJobTest.php` asserts save before anything else, and that one failing app does not stop the sweep.

## 2. Endpoint and setting

- [ ] 2.1 Add `GET /api/updates` to `ApiController`, admin-only. Verify: `tests/unit/Controller/ApiTest.php` asserts 403 for a non-admin and the snapshot shape for an admin.
- [ ] 2.2 Add `update.max_lines_behind` (0 to 10, empty is off) to `InstanceSettings` and a number field to `InstanceSettingsPanel.vue`. Verify: the settings unit test rejects 11 and -1, and `InstanceSettingsPanel.spec.ts` saves the value.

## 3. Page

- [ ] 3.1 Show "Installed {version}" on every card with an installed version. Verify: extend `tests/e2e/shell.spec.ts` "Admin views installed apps" to assert the version text on a known card, closing the existing requirement's gap.
- [ ] 3.2 Load `/api/updates` in the non-blocking group and render the "Update available" badge, the pinned variant, the policy flag and the freshness line. Verify: a vitest spec for the badge states (none, update, pinned, outside policy, not checked).
- [ ] 3.3 Add the "apps with an update" and "outside the policy" filter. Verify: vitest on `filteredApps`.
- [ ] 3.4 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.5 Add `tests/e2e/pending-updates.spec.ts` with the forge fixture serving a newer release, and assert the badge after `occ versioniq:updates --refresh`.

## 4. Command

- [ ] 4.1 Add `lib/Command/ListUpdates.php` (`versioniq:updates`, `--json`, `--refresh`, `--outside-policy`) and register it in `appinfo/info.xml`. Verify: `tests/unit/Command/ListUpdatesTest.php` covers the table, the JSON keys, and exit code 1 on an empty snapshot without `--refresh`; extend `tests/e2e/cli.spec.ts` with one JSON run.

## 5. Close

- [ ] 5.1 Set the matrix rows `inv-list-installed`, `inv-update-available`, `inv-version-lag`, `pin-still-notify` and `adm-cli-machine-output` to `built` with evidence lines, then archive this change.
