# sources-trust-audit-and-offline-feed tasks

## 1. Trusted-source audit

- [x] 1.1 Add `OPERATION_TRUST_SOURCE`, `OPERATION_UNTRUST_SOURCE` and `OPERATION_UNBIND_SOURCE` to `lib/Service/Audit/AuditLogger.php` and record them in `ApiController::addTrustedSource()`, `removeTrustedSource()` and `clearBinding()`. Verify: `tests/unit/Controller/ApiTest.php` asserts one entry per call with pattern or app.
  - `trust_source`, `untrust_source` and `unbind_source`. Trust and untrust are recorded by `TrustedSourceList::trust()`/`untrust()` (which `InstallerService::addTrustedPattern()`/`removeTrustedPattern()` now call), once per real change, with the pattern as the message and Versioniq as the app; `SourceBindingStore::clear()` records `unbind_source` instead of `bind_source`. Recording at the store rather than in `ApiController` covers every caller. PHPUnit `tests/unit/Service/Source/TrustedSourceAuditTest.php` and `SourceBindingStoreTest`.
- [x] 1.2 Render the three operations in `src/components/HistoryPanel.vue`. Verify: `src/components/HistoryPanel.spec.ts`.
  - `operationLabel()` in `src/utils/auditOperations.ts` names them ("Trusted source added: codeberg.org/zuiddrecht", "Trusted source removed: …", "Source binding cleared" with the binding message under it); `HistoryPanel.spec.ts`.

## 2. GitHub search switch

- [x] 2.1 Expose `discovery.github_search_enabled` through `InstanceSettings::read()` and `update()` (audited as `settings`). Verify: `tests/unit/Service/Settings/InstanceSettingsTest.php`.
  - `githubSearchEnabled` in `InstanceSettings::read()` (bool) and `update()` (`true`/`false`, stored in `discovery.github_search_enabled`, which `GithubSearchDiscovery` reads), audited with the other settings as `githubSearchEnabled=on|off`; the request field and the OpenAPI document follow. `InstanceSettingsTest`.
- [x] 2.2 Add the switch and the state line to `src/components/DiscoverPanel.vue`. Verify: `src/components/DiscoverPanel.spec.ts` toggles and re-searches.
  - The switch and the state line show from the GitHub search provider's `enabled` in the search answer, so opening the tab sends no extra request; turning it writes `githubSearchEnabled` through PUT /api/instance-settings (password confirmation, audited) and searches again. `DiscoverPanel.spec.ts`.

## 3. Local advisory copy

- [x] 3.1 Add `lib/Service/Advisory/LocalFeedCopy.php` (validate, store in IAppData `advisories/feed.json`, date of copy). Verify: `tests/unit/Service/Advisory/LocalFeedCopyTest.php` for a valid file, a broken file keeping the old copy, and the 30-day warning.
  - `LocalFeedCopy::import()` refuses a file that is not a non-empty JSON list of records with a `ghsa_id` before writing anything, so the previous copy stays; a valid file goes to IAppData `advisories/feed.json` with its import time (`advisory.feed_copy_at`) and record count. `isStale()` is true past 30 days. `LocalFeedCopyTest`.
- [x] 3.2 Add `advisory.feed_source` (`remote` or `local`) to `InstanceSettings`, and read the local copy in `NextcloudAdvisoryFeed` when it is `local`. Verify: `tests/unit/Service/Advisory/NextcloudAdvisoryFeedTest.php` asserts no HTTP call with a local source.
  - `advisoryFeedSource` (`remote`/`local`) in `InstanceSettings::read()`/`update()`, audited with the other settings, with `advisoryFeedCopiedAt` and `advisoryFeedCopyMaxAgeDays` for the page. `NextcloudAdvisoryFeed::fetchAll()` reads the copy with no HTTP client when the source is `local`, and reports a missing copy as an error, not as no advisories. `NextcloudAdvisoryFeedTest`, `InstanceSettingsTest`.
- [x] 3.3 Add `POST /api/advisory/feed-copy` (admin, `PasswordConfirmationRequired`, audited) and the import control in `src/components/InstanceSettingsPanel.vue`. Verify: `ApiTest.php` and `InstanceSettingsPanel.spec.ts`.
  - `SettingsController::importAdvisoryFeedCopy()` (admin, `PasswordConfirmationRequired`, audited as a settings change, 400 with the reason for a broken file) and in the settings panel the feed source choice, the copy's date ("Local copy of 20 Oct"), the 30-day warning and the file import. `SettingsControllerTest`, `InstanceSettingsPanel.feedCopy.spec.ts`.
- [x] 3.4 Add the strings to `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js`. Verify: `npm run check:l10n-js`.
  - Sixteen strings in `en` and `nl`; `npm run check:l10n-js` passes.

## 4. Close

- [ ] 4.1 Set the matrix rows `aud-log-source`, `src-discover-github` and `adv-offline-db` to `built` with evidence lines, then archive this change.
  - The three rows are `built` with evidence lines. Archiving is left for after the change is verified and merged.
