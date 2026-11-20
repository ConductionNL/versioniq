# Design: releases-version-facts

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AppStoreSource::normalizeVersions()` (`lib/Service/Source/AppStoreSource.php:586-636`) keeps three fields per release: `version`, the changelog from the release's `translations` (`lib/Service/Source/AppStoreSource.php:716-746`) and `serverCompatible`. Every other release field is dropped.
- `ForgeReleaseSource::listVersions()` (`lib/Service/Source/ForgeReleaseSource.php:91-122`) keeps `tag_name` and the release `body` (`lib/Service/Source/ForgeReleaseSource.php:506-517`). The asset that matches the binding's pattern is only picked at install time, in `buildReleasePayload()` (`lib/Service/Source/ForgeReleaseSource.php:385-443`).
- `InstallerService::getAppVersions()` (`lib/Service/InstallerService.php:185`) passes both lists through the same steps: it truncates each changelog to 8 KiB (`lib/Service/InstallerService.php:229`, the cap at `lib/Service/InstallerService.php:52`), then stamps the recorded digest, the offline flag and the cached server range (`lib/Service/InstallerService.php:230-232`).
- The picker renders one row per version (`src/App.vue:2434-2465`): the number, `ServerCompatBadge`, the offline and digest badges, and `VersionChangelog`, which shows the notes as plain text (`src/components/VersionChangelog.vue`).
- The range summary counts the major, minor and patch steps between the installed and the chosen version (`src/App.vue:1424-1430`, text at `src/App.vue:1443-1453`); the downgrade dialog repeats it (`src/dialogs/DowngradeConfirmDialog.vue:76-78`).
- Every install through Versioniq writes an audit row with `to_version` and `status` (`lib/Service/SelectedReleaseInstallerService.php:445-455`, `lib/Service/ExternalReleaseInstallerService.php:411-421`). `AuditEntryMapper` only pages rows (`lib/Db/AuditEntryMapper.php:50`).

## Goals and non-goals

Goals: four facts per version entry, computed on the server once, shown in the picker and the CLI.

Non-goals: acting on the facts (the minimum age is `releases-minimum-age`), a central adoption service, or rating versions.

## Decisions

### D1. The release date

- App Store: the release's `created` field. The App Store API v1 carries it on every release; `lastModified` changes when the entry is edited and is not a release date.
- Forge: the release's `published_at`. A release that was never published (a draft) carries none and stays null.

Both are normalised to ISO 8601 UTC as `releasedAt`, or null when absent or unreadable. The picker prints the date in the admin's locale with `@nextcloud/l10n`, and a tooltip with the full time. `occ versioniq:versions` adds a "Released" column.

Alternative considered: record the first time Versioniq saw a version. Rejected for this row: that is when this instance noticed it, not when it came out. `releases-minimum-age` keeps first-seen as its fallback for a version without a date.

### D2. Adoption: what a source publishes, and what the admin's own instances did

- Forge: the `download_count` of the release asset that matches the binding's asset pattern, with the same `fnmatch` rule `buildReleasePayload()` uses. It is stored as `downloads`. No match, or more than one, gives null.
- App Store: the catalogue publishes no per-release download count, so `downloads` is null and the picker shows nothing, rather than a zero.
- Install outcomes: `AuditEntryMapper::countOutcomesByVersion(appId)` groups the app's `install` rows by `to_version` and `status`. `getAppVersions()` stamps `outcomes: {succeeded, failed}` per version from it. A new admin-only `GET /api/app/{appId}/outcomes` returns the same counts, so another Versioniq can read them. When connections from `install-multi-instance-promotion` exist, the picker reads each connected instance's outcomes through the stored connection and shows the totals: "Installed 4 times on 3 instances, 1 failure."

Alternative considered: ask the App Store for its public download counter per app. Rejected. It counts the app, not the version, and says nothing about whether the update worked.

### D3. The breaking marker

`ReleaseNotesReader::isBreaking(string $notes)` is true when the notes contain one of:

- a heading, at any level, whose text is "Breaking changes" or "Breaking change", with or without the warning sign release tools put in front of it;
- a line starting with `BREAKING CHANGE:` or `BREAKING-CHANGE:`, the Conventional Commits footer.

It reads the full notes, before the 8 KiB truncation, so a marker at the end of a long changelog is not lost. `getAppVersions()` stamps `breaking` per version. A version is also shown as a major step when its major number is higher than the installed one, as today; the two facts are separate badges ("Major version", "Breaking").

The range summary gains one sentence when any version between the installed and the chosen one is `breaking`: "Includes releases marked as breaking: 3.0.0, 3.2.0." The downgrade dialog shows the same sentence.

Alternative considered: treat every major step as breaking. Rejected. That is the half already built, and the row asks for the publisher's own flag.

### D4. Known issues

Two sources, shown together under "Known issues" above the rest of the notes:

- **From the notes.** `ReleaseNotesReader::knownIssues(string $notes)` returns the section under a heading "Known issues" or "Known problems", up to the next heading of the same or a higher level. A forge release body can be edited after release, so a problem the publisher adds later appears on the next listing. The section is shown as plain text, like the rest of the notes.
- **From an admin.** `KnownIssueStore` keeps `known_issues.{appId}` as a JSON map from version to a list of `{text, workaround, link, addedBy, addedAt}`, at most 20 entries per app, oldest dropped first. `PUT /api/app/{appId}/versions/{version}/known-issues` and `DELETE .../known-issues/{index}` are admin-only and password-confirmed, and each change is audited as `settings`. A link must be https.

The app card shows "Known issue in the installed version" when the installed version has an admin note, with the workaround in its tooltip.

Alternative considered: search the forge's issue tracker for the version number. Rejected. It needs extra calls per version and a token, and it cannot tell a real problem from a question.

## Risks and trade-offs

- [The breaking marker misses a publisher who writes "incompatible" instead] → the badge is a statement of the publisher's own flag; the major step badge still shows a major move.
- [Download counts favour older releases, which had more time] → the picker shows the count next to `releasedAt`, so the admin sees both.
- [Outcomes read from connected instances slow the picker] → they are fetched after the version list renders, with the 15 s timeout of the connection reader, and a failed instance is left out with a note.
- [An admin note outlives the version] → notes stay keyed by version and show only on that version; the 20-entry cap drops the oldest.

## Migration

No schema change. The new fields are additive in the version envelope and in `--json` output.
