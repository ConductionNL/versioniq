# releases-version-facts tasks

## 1. Release date and downloads

- [x] 1.1 Read `created` in `AppStoreSource::normalizeVersions()` and `published_at` in `ForgeReleaseSource::listVersions()` into `releasedAt` (design D1). Verify: `tests/unit/Service/Source/AppStoreSourceTest.php` and a forge source test cover a date, a missing date and an unreadable one.
- [x] 1.2 Read the matching asset's `download_count` into `downloads` in `ForgeReleaseSource::listVersions()`, with the `buildReleasePayload()` pattern rule (design D2). Verify: a forge source test covers one match, no match and two matches.

## 2. Outcomes

- [x] 2.1 Add `AuditEntryMapper::countOutcomesByVersion()` and `lib/Service/Release/InstallOutcomes.php`; stamp `outcomes` in `getAppVersions()`. Verify: `tests/unit/Service/Release/InstallOutcomesTest.php` counts success and failure rows per version and ignores other operations.
- [x] 2.2 Add `GET /api/app/{appId}/outcomes`, admin-only. Verify: `tests/unit/Controller/ApiTest.php` asserts 403 for a non-admin and the counts for an admin.
- [x] 2.3 When `install-multi-instance-promotion` has landed, read each connection's outcomes in the picker after the list renders, and name an instance that failed. Verify: a vitest spec for the totals text with two instances and one unreadable instance.
      (Built: `RemoteInstanceReader::outcomes()` and `allOutcomes()` read `GET /ocs/v2.php/apps/versioniq/api/app/{appId}/outcomes` from every stored connection with its admin account, uncached, through the same request as the manifest read, with its 15 s timeout of the connection reader and no password in any log line; a 401/403, a 404 (older Versioniq), another status, an unreadable body or an unreachable instance gives that connection an error and does not stop the others. The admin-only `GET /api/app/{appId}/outcomes/instances` on `InstanceController` returns `{id, label, outcomes|null, error|null}` per connection (added to `openapi.json` by hand, since `composer openapi` stops on an unrelated extractor error). The picker asks for it once the version list has rendered and never waits for it; each row then reads "Installed 2 times on 2 instances, 1 failure" across this instance and the readable connections (`instanceTotalsText` in `src/utils/versionFacts.ts`), and one note above the list names every connection it could not read, with the reason in its tooltip. Tests: the outcomes cases in `RemoteInstanceReaderTest`, the outcomes cases in `InstanceControllerTest`, the "instance totals" block in `versionFacts.spec.ts` (two instances and one unreadable), `VersionFacts.spec.ts` and `App.versionDetail.spec.ts`.)

## 3. Release notes

- [x] 3.1 Add `lib/Service/Release/ReleaseNotesReader.php` with `isBreaking()` and `knownIssues()` (design D3, D4), and call it in `getAppVersions()` before `applyChangelogTruncation()`. Verify: `tests/unit/Service/Release/ReleaseNotesReaderTest.php` covers each heading form, the Conventional Commits footer, a marker past 8 KiB, a known-issues section ending at the next heading, and notes with neither.
- [x] 3.2 Add `lib/Service/Release/KnownIssueStore.php` and `PUT /api/app/{appId}/versions/{version}/known-issues` and `DELETE .../known-issues/{index}`, admin-only, password-confirmed, audited as `settings`, https-only links, 20 entries per app. Verify: `tests/unit/Service/Release/KnownIssueStoreTest.php` and `tests/unit/Controller/ApiTest.php` cover the cap, an http link refused, 403 and the audit row.

## 4. Page and CLI

- [x] 4.1 Add `src/components/VersionFacts.vue` to each picker row (date, downloads, outcomes, "Breaking", "Major version", known issues) and `src/dialogs/KnownIssueDialog.vue`; add the breaking sentence to the range summary and the downgrade dialog; add the card notice. Verify: `src/components/VersionFacts.spec.ts` covers each fact present and absent; a vitest spec covers the range sentence.
      (Built: `src/components/VersionFacts.vue` on every picker row, with the downloads, the outcomes here, the "Major version" and "Breaking" badges, and "Known issues": the publisher's section from the notes as plain text and the admin notes, each with its workaround, https link and a remove button; `src/dialogs/KnownIssueDialog.vue` adds a note through the password-confirmed endpoint. The range summary and the downgrade dialog say "Includes releases marked as breaking: …" (`src/utils/versionFacts.ts`). The app card says "Known issue in the installed version", with the workarounds in its tooltip, from the new `installedKnownIssues` on each app card. Specs: `VersionFacts.spec.ts`, `versionFacts.spec.ts`, `KnownIssueDialog.spec.ts`, the breaking row in `DowngradeConfirmDialog.spec.ts`, the card notice in `App.appCard.spec.ts`, and the range sentence, removal and dialog wiring in `App.versionDetail.spec.ts`; the installed-version notes in `InstallerServiceAppListGoldenTest`.)
- [x] 4.2 Add the "Released" column and the new JSON keys to `lib/Command/ListVersions.php`. Verify: `tests/unit/Command/ListVersionsTest.php`.
- [x] 4.3 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
      (Built: 25 strings in `l10n/en.json` and `l10n/nl.json`, `.js` rebuilt; `check:l10n-js` and `check-l10n-keys` pass.)
- [x] 4.4 Extend `tests/e2e/versions.spec.ts`: the forge fixture serves a release with `published_at`, a breaking heading and a known-issues section; assert the date, the badge and the section.

## 5. Close

- [ ] 5.1 Set the matrix rows `rel-release-date`, `rel-adoption`, `rel-breaking-flag` and `rel-known-issues` to `built` with evidence lines, then archive this change.
