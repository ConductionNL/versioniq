# releases-channel-and-prereleases tasks

## 1. Pre-release flag

- [ ] 1.1 Copy `isNightly` in `AppStoreSource::normalizeVersions()` and `prerelease` in `ForgeReleaseSource::listVersions()` onto each entry as `sourcePreRelease`; confirm `isNightly` on a live App Store catalogue entry and record the result in the PR. Verify: `tests/unit/Service/Source/AppStoreSourceTest.php` and a forge source test cover a flagged and an unflagged release, and an absent flag.
- [ ] 1.2 Add `lib/Service/Channel/ReleaseChannel.php` with `isPreRelease()` and `admits()` for the four channels (design D1, D2, D4). Verify: `tests/unit/Service/Channel/ReleaseChannelTest.php` covers the suffix rule, both source flags, `server` on each server channel and on an unknown one, `stable`, `beta`, `lts` with marked, publisher-marked and no lines.

## 2. Store and endpoints

- [ ] 2.1 Add `lib/Service/Channel/ChannelStore.php` (`channel.default`, `channel.{appId}`, `lts_lines.{appId}`), with malformed values read as absent. Verify: `tests/unit/Service/Channel/ChannelStoreTest.php`.
- [ ] 2.2 Stamp `preRelease`, `lts` and `onChannel` in `InstallerService::getAppVersions()` and return `channel` in the envelope. Verify: `tests/unit/Service/InstallerServiceTest.php` gains an app with its own channel and an app on the default.
- [ ] 2.3 Add `GET /api/channels`, `PUT /api/channel`, `PUT/DELETE /api/app/{appId}/channel` and `PUT /api/app/{appId}/lts-lines`, admin-only, password-confirmed, audited as `settings`. Verify: `tests/unit/Controller/ApiTest.php` asserts 403 for a non-admin, 400 for an unknown channel, and one audit row per change.

## 3. Page

- [ ] 3.1 Add `src/components/ChannelSelector.vue` on the app card, with the lines field for `lts`, and the default on the Settings tab. Verify: `src/components/ChannelSelector.spec.ts` covers each channel and the lines field.
- [ ] 3.2 Filter the picker on `onChannel` with the "Show all versions" switch and the tags; make `isBlockedBySafeMode()` read `onChannel`. Verify: `src/utils/safeMode.spec.ts` covers an off-channel version blocked and a beta-channel pre-release allowed; a vitest spec on the picker filter covers the switch and the "no lines marked" message.
- [ ] 3.3 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.4 Extend `tests/e2e/versions.spec.ts`: the forge fixture serves a release marked pre-release; assert it is hidden, then shown with the switch, then listed after the app's channel is set to `beta`.

## 4. Nightly job and sweep

- [ ] 4.1 Filter entries on `onChannel` in `AutoUpdateJob::processApp()` before `select()`. Verify: `tests/unit/BackgroundJob/AutoUpdateJobTest.php` covers policy `all` on a stable channel skipping a pre-release, and on a beta channel taking it.
- [ ] 4.2 When `inventory-pending-updates` has landed, make its availability sweep count versions with `onChannel` instead of the plain-version pattern. Verify: its `AvailabilityServiceTest` gains a beta-channel app whose newest version is a pre-release.

## 5. Command

- [ ] 5.1 Add the channel column to `lib/Command/ListVersions.php`, and `lib/Command/SetChannel.php` (`versioniq:channel`, `--lts-lines`) registered in `appinfo/info.xml`. Verify: `tests/unit/Command/ListVersionsTest.php` asserts the column and JSON keys; `tests/unit/Command/SetChannelTest.php` covers set, read and an unknown channel; extend `tests/e2e/cli.spec.ts` with one `versioniq:channel` run.

## 6. Close

- [ ] 6.1 Set the matrix rows `rel-prerelease`, `rel-channel` and `rel-lts-only` to `built` with evidence lines, then archive this change.
