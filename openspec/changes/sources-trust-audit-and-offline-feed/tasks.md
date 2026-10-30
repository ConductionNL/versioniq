# sources-trust-audit-and-offline-feed tasks

## 1. Trusted-source audit

- [ ] 1.1 Add `OPERATION_TRUST_SOURCE`, `OPERATION_UNTRUST_SOURCE` and `OPERATION_UNBIND_SOURCE` to `lib/Service/Audit/AuditLogger.php` and record them in `ApiController::addTrustedSource()`, `removeTrustedSource()` and `clearBinding()`. Verify: `tests/unit/Controller/ApiTest.php` asserts one entry per call with pattern or app.
- [ ] 1.2 Render the three operations in `src/components/HistoryPanel.vue`. Verify: `src/components/HistoryPanel.spec.ts`.

## 2. GitHub search switch

- [ ] 2.1 Expose `discovery.github_search_enabled` through `InstanceSettings::read()` and `update()` (audited as `settings`). Verify: `tests/unit/Service/Settings/InstanceSettingsTest.php`.
- [ ] 2.2 Add the switch and the state line to `src/components/DiscoverPanel.vue`. Verify: `src/components/DiscoverPanel.spec.ts` toggles and re-searches.

## 3. Local advisory copy

- [ ] 3.1 Add `lib/Service/Advisory/LocalFeedCopy.php` (validate, store in IAppData `advisories/feed.json`, date of copy). Verify: `tests/unit/Service/Advisory/LocalFeedCopyTest.php` for a valid file, a broken file keeping the old copy, and the 30-day warning.
- [ ] 3.2 Add `advisory.feed_source` (`remote` or `local`) to `InstanceSettings`, and read the local copy in `NextcloudAdvisoryFeed` when it is `local`. Verify: `tests/unit/Service/Advisory/NextcloudAdvisoryFeedTest.php` asserts no HTTP call with a local source.
- [ ] 3.3 Add `POST /api/advisory/feed-copy` (admin, `PasswordConfirmationRequired`, audited) and the import control in `src/components/InstanceSettingsPanel.vue`. Verify: `ApiTest.php` and `InstanceSettingsPanel.spec.ts`.
- [ ] 3.4 Add the strings to `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js`. Verify: `npm run check:l10n-js`.

## 4. Close

- [ ] 4.1 Set the matrix rows `aud-log-source`, `src-discover-github` and `adv-offline-db` to `built` with evidence lines, then archive this change.
