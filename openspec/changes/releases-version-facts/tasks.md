# releases-version-facts tasks

## 1. Release date and downloads

- [ ] 1.1 Read `created` in `AppStoreSource::normalizeVersions()` and `published_at` in `ForgeReleaseSource::listVersions()` into `releasedAt` (design D1). Verify: `tests/unit/Service/Source/AppStoreSourceTest.php` and a forge source test cover a date, a missing date and an unreadable one.
- [ ] 1.2 Read the matching asset's `download_count` into `downloads` in `ForgeReleaseSource::listVersions()`, with the `buildReleasePayload()` pattern rule (design D2). Verify: a forge source test covers one match, no match and two matches.

## 2. Outcomes

- [ ] 2.1 Add `AuditEntryMapper::countOutcomesByVersion()` and `lib/Service/Release/InstallOutcomes.php`; stamp `outcomes` in `getAppVersions()`. Verify: `tests/unit/Service/Release/InstallOutcomesTest.php` counts success and failure rows per version and ignores other operations.
- [ ] 2.2 Add `GET /api/app/{appId}/outcomes`, admin-only. Verify: `tests/unit/Controller/ApiTest.php` asserts 403 for a non-admin and the counts for an admin.
- [ ] 2.3 When `install-multi-instance-promotion` has landed, read each connection's outcomes in the picker after the list renders, and name an instance that failed. Verify: a vitest spec for the totals text with two instances and one unreadable instance.

## 3. Release notes

- [ ] 3.1 Add `lib/Service/Release/ReleaseNotesReader.php` with `isBreaking()` and `knownIssues()` (design D3, D4), and call it in `getAppVersions()` before `applyChangelogTruncation()`. Verify: `tests/unit/Service/Release/ReleaseNotesReaderTest.php` covers each heading form, the Conventional Commits footer, a marker past 8 KiB, a known-issues section ending at the next heading, and notes with neither.
- [ ] 3.2 Add `lib/Service/Release/KnownIssueStore.php` and `PUT /api/app/{appId}/versions/{version}/known-issues` and `DELETE .../known-issues/{index}`, admin-only, password-confirmed, audited as `settings`, https-only links, 20 entries per app. Verify: `tests/unit/Service/Release/KnownIssueStoreTest.php` and `tests/unit/Controller/ApiTest.php` cover the cap, an http link refused, 403 and the audit row.

## 4. Page and CLI

- [ ] 4.1 Add `src/components/VersionFacts.vue` to each picker row (date, downloads, outcomes, "Breaking", "Major version", known issues) and `src/dialogs/KnownIssueDialog.vue`; add the breaking sentence to the range summary and the downgrade dialog; add the card notice. Verify: `src/components/VersionFacts.spec.ts` covers each fact present and absent; a vitest spec covers the range sentence.
- [ ] 4.2 Add the "Released" column and the new JSON keys to `lib/Command/ListVersions.php`. Verify: `tests/unit/Command/ListVersionsTest.php`.
- [ ] 4.3 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 4.4 Extend `tests/e2e/versions.spec.ts`: the forge fixture serves a release with `published_at`, a breaking heading and a known-issues section; assert the date, the badge and the section.

## 5. Close

- [ ] 5.1 Set the matrix rows `rel-release-date`, `rel-adoption`, `rel-breaking-flag` and `rel-known-issues` to `built` with evidence lines, then archive this change.
