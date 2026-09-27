# autoupdate-default-and-config-file tasks

## 1. Default level

- [ ] 1.1 Add `auto_update_default_level` to `AutoUpdateSettingsStore` and the settings API, and `PolicyStore::effectiveLevelFor()`. Verify: `tests/unit/Service/Policy/PolicyStoreTest.php` for a stored level, a default and none.
- [ ] 1.2 Make `AutoUpdateJob` visit every enabled manageable app with its effective level. Verify: `tests/unit/BackgroundJob/AutoUpdateJobTest.php`: with default none nothing changes; with default patch an app without a policy gets a patch update; a pinned app is still skipped; drive it once in `tests/e2e/jobs.spec.ts` with the `fixtureapp` fixture.
- [ ] 1.3 Show own or default on each card, the reset action, and the default on the Automatic updates section with the count of apps that follow it. Verify: `PolicySelector.spec.ts` and `AutoUpdateOverview.spec.ts`.

## 2. File

- [ ] 2.1 Add `PolicyFile` with the shape and validation of design D3. Verify: `tests/unit/Service/Policy/PolicyFileTest.php` with a valid file and one error per rule, each naming its JSON path.
- [ ] 2.2 Add `occ versioniq:policies:export` and `occ versioniq:policies:import` with `--dry-run` and `--prune`, and register them. Verify: unit tests; `tests/e2e/cli.spec.ts` exports, edits one level, imports with `--dry-run` (no change) and without it (change and audit row).
- [ ] 2.3 Add the file mode of design D4. Verify: unit tests that an invalid file makes the job do nothing, and that policy writes answer 409 in file mode; the page renders the read-only state (vitest).

## 3. Close

- [ ] 3.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `aut-global-default` and `aut-config-as-code` to `built` with evidence lines, then archive this change.
