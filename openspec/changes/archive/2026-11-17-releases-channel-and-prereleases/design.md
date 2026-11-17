# Design: releases-channel-and-prereleases

Read against `development` at 02e1050 (2026-09-27).

## Context

- `GET /api/update-channel` (`lib/Controller/ApiController.php:161-171`) returns `OCP\ServerVersion::getChannel()`. `src/App.vue:518-526` loads it and prints it as a read-only label.
- Safe mode lives in the browser. `isBlockedBySafeMode()` (`src/utils/safeMode.ts:50-61`) blocks a version older than the installed one, and a version whose number carries a suffix (`isPreRelease()`, `src/utils/safeMode.ts:29-31`) while the server channel is `stable`, `production` or `enterprise` (`src/utils/safeMode.ts:22`). The picker filters with it (`src/App.vue:1223-1234`). The switch is stored in the browser's local storage (`src/App.vue:1873-1876`), so it differs per admin and per browser.
- The App Store list is built in `AppStoreSource::normalizeVersions()` (`lib/Service/Source/AppStoreSource.php:586`), which keeps `version`, the changelog and `serverCompatible` and drops every other release field.
- The forge list is built in `ForgeReleaseSource::listVersions()` (`lib/Service/Source/ForgeReleaseSource.php:91-122`), which reads `tag_name` and the body and ignores the release's own pre-release flag.
- The nightly job maps the version list to plain strings (`lib/BackgroundJob/AutoUpdateJob.php:150-154`) and hands them to `CandidateSelector::select()` (`lib/Service/AutoUpdate/CandidateSelector.php:46`). Levels `patch` and `minor` accept only a plain `major.minor.patch` (`lib/Service/AutoUpdate/CandidateSelector.php:36`); level `all` accepts anything newer (`lib/Service/AutoUpdate/CandidateSelector.php:67-68`), a pre-release included.
- `InstallerService::getAppVersions()` (`lib/Service/InstallerService.php:185`) is the single place both sources' lists pass through before the page, the CLI and the job see them.

## Goals and non-goals

Goals: one rule, on the server, for which versions an app's channel admits; the picker, the nightly job, the CLI and safe mode all read it.

Non-goals: changing the server's update channel; blocking a manual install of a version outside the channel (Versioniq installs any version on purpose, and safe mode stays the guard for the picker).

## Decisions

### D1. What a pre-release is

`ReleaseChannel::isPreRelease(array $entry)` returns true when any of these holds:

- the version number has a suffix after its numeric core, the pattern `^v?\d+(\.\d+)*-.+` that `safeMode.ts` already uses;
- a forge release carries `prerelease: true` (GitHub and Forgejo both return it on the releases endpoint);
- an App Store release carries `isNightly: true`.

`normalizeVersions()` and `listVersions()` copy the flag onto the entry as `sourcePreRelease`. `getAppVersions()` then stamps `preRelease` on every entry from the rule above.

Alternative considered: keep the suffix rule only. Rejected. A publisher who tags `2.5.0` and ticks "pre-release" on GitHub means it, and the suffix rule would offer it as stable.

### D2. The channels

| Channel | Admits |
|---|---|
| `server` | what the server channel admits: no pre-releases on `stable`, `production`, `enterprise`; pre-releases on `beta`, `daily`, `git`; everything when the channel is unknown (the rule safe mode uses today) |
| `stable` | versions that are not pre-releases |
| `beta` | every version |
| `lts` | versions that are not pre-releases and lie on a long-term support line (D4) |

`ChannelStore` keeps `channel.default` (default `server`) and `channel.{appId}` (absent means the default). The effective channel of an app is its own, else the default. `getAppVersions()` stamps `onChannel` and returns `channel` (the effective one) in the envelope.

Alternative considered: set the server's `updater.release.channel` from Versioniq. Rejected. That setting also drives the server updater, and Nextcloud's own admin page owns it.

### D3. Where the rule applies

- **Picker.** `src/App.vue` hides entries with `onChannel: false` unless the new "Show all versions" switch is on. A revealed entry carries a "Pre-release" or "Not long-term support" tag. The switch is per page view and resets when another app is chosen.
- **Safe mode.** `isBlockedBySafeMode()` takes the entry's `onChannel` instead of comparing the version with the server channel. Its downgrade rule is unchanged.
- **Nightly job.** `AutoUpdateJob` keeps only entries with `onChannel: true` before calling `select()`. `CandidateSelector` itself is unchanged.
- **CLI.** `occ versioniq:versions` adds a "Channel" column (`on` or the reason it is off) and `channel` to its JSON.
- **Install.** A manual install of an off-channel version is not refused by the server; the picker only shows it after the switch.

Alternative considered: filter the list on the server. Rejected. The picker needs the full list to reveal the rest, and the CLI must still list every version.

### D4. Long-term support lines

The App Store has no long-term support field, and Nextcloud apps publish no such list. So the lines come from two places:

- An admin marks lines per app, as `major.minor` or `major`, stored under `lts_lines.{appId}` as a JSON list. A version lies on a line when its core starts with it.
- A forge release whose name or tag contains the word `LTS`, as a whole word in any case, is on a long-term support line by the publisher's own statement, and its `major.minor` line counts as marked.

An app on the `lts` channel with no marked line admits nothing, and the picker says so: "No long-term support lines are marked for this app." It never falls back to all versions, because that would offer exactly what the admin asked not to see.

Alternative considered: treat the newest release line of each major as long-term support. Rejected. That is a guess about a publisher's support policy, and a wrong guess sends an admin onto an unsupported line.

### D5. Endpoints and settings

- `GET /api/channels` returns the default, every per-app channel and every app's marked lines.
- `PUT /api/channel` sets the default; `PUT /api/app/{appId}/channel` and `DELETE /api/app/{appId}/channel` set and clear an app's channel; `PUT /api/app/{appId}/lts-lines` sets its lines. All admin-only and password-confirmed, and each change is audited as operation `settings` with the old and new value in the message, the way `SettingsController` records a setting today (`lib/Controller/SettingsController.php:121-133`).
- The Settings tab gets the instance default. Each app card gets a `ChannelSelector` next to the policy selector, and the lines field shows when `lts` is chosen.
- `occ versioniq:channel [<appId>] [<channel>] [--lts-lines=2.3,2.4]` reads or sets them.

## Risks and trade-offs

- [A policy of `all` on a stable server stops taking pre-releases] → that is the fix the row asks for. An admin who wants them sets the app's channel to `beta`. The migration note below says so, and the release notes name it.
- [A publisher ticks "pre-release" by mistake] → the picker's switch still shows the version, and a manual install works.
- [`isNightly` is not present on every App Store payload] → an absent flag reads as not nightly, so the suffix rule still applies. Task 1.1 checks the field on a live catalogue entry.
- [The word LTS in a tag is a weak signal] → it only adds a line; it never removes one an admin marked, and the admin sees which lines came from the publisher.

## Migration

No schema change. Absent keys mean `server`, which reproduces today's safe mode rule. One behaviour changes on upgrade: on a stable server channel, the nightly job no longer installs a pre-release under a policy of `all`.
