# inventory-pins-checks-and-pending tasks

## 1. Pinned filter

- [ ] 1.1 Add a "Pinned" count to the status bar in `src/App.vue` and a pinned filter in `filteredApps`, listed under "Active". Verify: `src/App.appCard.spec.ts` filters three pinned apps out of five and clears the filter.

## 2. Sweep outcome

- [ ] 2.1 Store `status` and `errors` next to `checkedAt` in `AdvisoryResultStore::save()` and `AvailabilityResultStore::save()`, set by `AdvisoryRefreshJob` and `AvailabilityRefreshJob`. Verify: `tests/unit/Service/Advisory/AdvisoryResultStoreTest.php` and `tests/unit/Service/Availability/AvailabilityResultStoreTest.php` for ok, partial and failed.
- [ ] 2.2 Return `status` and `errors` from `GET /api/advisories` and `GET /api/updates` in `ApiController`. Verify: `tests/unit/Controller/ApiTest.php`.
- [ ] 2.3 Render the failed and partial check line in `src/App.vue`. Verify: a vitest case per state.

## 3. Pending updates

- [ ] 3.1 Pass the stored availability and the pins into `src/components/AutoUpdateOverview.vue` and list each pending version with its state (scheduled, waiting for approval, held by pin, held by minimum age, failed). Verify: `src/components/AutoUpdateOverview.spec.ts`.

## 4. Provenance

- [ ] 4.1 Add `provenance` to `InstallerService::getInstalledApps()` from the last-known-good record against the installed version. Verify: `tests/unit/Service/InstallerServiceAppListTest.php` for a matching record, a differing version and no record.
- [ ] 4.2 Show the provenance in `src/components/AppSourceBadges.vue`, separate from the binding. Verify: `src/components/AppSourceBadges.spec.ts`.
- [ ] 4.3 Add the strings to `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js`. Verify: `npm run check:l10n-js`.

## 5. Close

- [ ] 5.1 Set the matrix rows `pin-list`, `adv-last-checked`, `aut-dashboard` and `inv-source-shown` to `built` with evidence lines, then archive this change.
