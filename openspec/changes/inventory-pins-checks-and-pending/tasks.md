# inventory-pins-checks-and-pending tasks

## 1. Pinned filter

- [x] 1.1 Add a "Pinned" count to the status bar in `src/App.vue` and a pinned filter in `filteredApps`, listed under "Active". Verify: `src/App.appCard.spec.ts` filters three pinned apps out of five and clears the filter.
  - The Apps tab has no status bar yet, so the Pinned count is a toggle next to Show filters. Spec: `src/App.pinsAndChecks.spec.ts`.

## 2. Sweep outcome

- [x] 2.1 Store `status` and `errors` next to `checkedAt` in `AdvisoryResultStore::save()` and `AvailabilityResultStore::save()`, set by `AdvisoryRefreshJob` and `AvailabilityRefreshJob`. Verify: `tests/unit/Service/Advisory/AdvisoryResultStoreTest.php` and `tests/unit/Service/Availability/AvailabilityResultStoreTest.php` for ok, partial and failed.
- [x] 2.2 Return `status` and `errors` from `GET /api/advisories` and `GET /api/updates` in `ApiController`. Verify: `tests/unit/Controller/ApiTest.php`.
- [x] 2.3 Render the failed and partial check line in `src/App.vue`. Verify: a vitest case per state.

## 3. Pending updates

- [x] 3.1 Pass the stored availability and the pins into `src/components/AutoUpdateOverview.vue` and list each pending version with its state (scheduled, waiting for approval, held by pin, held by minimum age, failed). Verify: `src/components/AutoUpdateOverview.spec.ts`.
  - States built: scheduled, held by the pin, not covered by the policy level, failed earlier (`src/utils/pendingAutoUpdates.ts`). Waiting for approval and held by minimum age have no data yet (approval is `autoupdate-proposed-updates`, and there is no minimum-age setting); they get a state when those exist.

## 4. Provenance

- [x] 4.1 Add `provenance` to `InstallerService::getInstalledApps()` from the last-known-good record against the installed version. Verify: `tests/unit/Service/InstallerServiceAppListTest.php` for a matching record, a differing version and no record.
- [x] 4.2 Show the provenance in `src/components/AppSourceBadges.vue`, separate from the binding. Verify: `src/components/AppSourceBadges.spec.ts`.
- [x] 4.3 Add the strings to `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js`. Verify: `npm run check:l10n-js`.

## 5. Close

- [ ] 5.1 Set the matrix rows `pin-list`, `adv-last-checked`, `aut-dashboard` and `inv-source-shown` to `built` with evidence lines, then archive this change.
