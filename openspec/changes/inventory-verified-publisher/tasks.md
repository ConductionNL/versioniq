# inventory-verified-publisher tasks

## 1. Data

- [ ] 1.1 Add `authors` (names only) and `isFeatured` to `AppStoreDiscovery::CACHED_FIELDS`, and `publisher` and `marks` to `DiscoveryHit`. Verify: `tests/unit/Service/Discovery/AppStoreDiscoveryTest.php` asserts no author e-mail is cached.
- [ ] 1.2 Add `PublisherTrust` with the four marks and the weekly GitHub organisation cache. Verify: `tests/unit/Service/Discovery/PublisherTrustTest.php` for a shipped app, a featured app, a verified organisation, a user account and a trusted source.
- [ ] 1.3 Add `similarTo` in `DiscoveryAggregator`. Verify: a unit test with "Whiteboard", "white-board" and "WhiteboardApp" from two publishers.
- [ ] 1.4 Add publisher and marks to `getInstalledApps()`. Verify: `tests/unit/Service/InstallerServiceAppListTest.php`.

## 2. Page

- [ ] 2.1 Add `PublisherMark.vue` and use it in `DiscoverPanel.vue` and on the app card, with the similar-name hint. Verify: `PublisherMark.spec.ts` and an extended `DiscoverPanel.spec.ts`.
- [ ] 2.2 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 2.3 Extend `tests/e2e/discovery.spec.ts`: a search in the App Store fixture shows the publisher and the featured mark on a featured app.

## 3. Close

- [ ] 3.1 Set the matrix row `inv-verified-publisher` to `built` with evidence lines, then archive this change.
