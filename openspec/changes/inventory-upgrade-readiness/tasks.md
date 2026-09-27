# inventory-upgrade-readiness tasks

## 1. Ranges on versions

- [ ] 1.1 Read `phpVersionSpec` in `AppStoreSource::normalizeVersions()`, add `phpSpec`, `platformSpec` and `phpCompatible`, and add `satisfiesPhpSpec()`. Verify: `tests/unit/Service/Source/AppStoreSourceTest.php` covers a matching, a failing and an absent PHP range.
- [ ] 1.2 Add `Forge::fileAtRefEndpoint()` and `ForgeRangeStore`, and stamp stored or freshly read ranges in `InstallerService::getAppVersions()` for at most five uncached forge versions. Verify: a unit test with a stubbed forge answer, one for a missing `info.xml` (stored as no range, not fetched again), and one that a cached archive still wins.
- [ ] 1.3 Show the PHP verdict in `ServerCompatBadge.vue`. Verify: `ServerCompatBadge.spec.ts` renders the PHP line only when `phpCompatible` is not null.

## 2. Readiness

- [ ] 2.1 Add `UpgradeReadinessService` with the five verdicts of design D3. Verify: `tests/unit/Service/Readiness/UpgradeReadinessServiceTest.php` has one case per verdict.
- [ ] 2.2 Add `UpgradeReadinessJob`, `GET` and `POST /api/readiness`, admin-only, the POST password-confirmed. Verify: `tests/unit/Controller/ApiTest.php` asserts 403 for a non-admin and that POST queues a job with both targets.
- [ ] 2.3 Add `lib/Command/CheckReadiness.php` (`versioniq:readiness --server --php --json`) and register it. Verify: `tests/unit/Command/CheckReadinessTest.php` and one JSON run in `tests/e2e/cli.spec.ts`.

## 3. Page

- [ ] 3.1 Add `ReadinessPanel.vue` and the tab, with defaults, the Check button, the report time and the grouped table. Verify: `ReadinessPanel.spec.ts` for the empty, queued and filled states.
- [ ] 3.2 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.3 Add `tests/e2e/readiness.spec.ts`: an admin checks readiness for the running major and sees every installed app in a group.

## 4. Close

- [ ] 4.1 Set the matrix rows `inv-server-upgrade-readiness`, `inv-compat-php` and `inv-compat-server` to `built` with evidence lines, then archive this change.
