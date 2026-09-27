---
kind: code
---

# Proposal: releases-channel-and-prereleases

## Why

An admin who wants to try a beta of one app, while every other app stays on stable releases, cannot say so in Versioniq. The Apps tab shows the server's update channel as a read-only label. Safe mode hides pre-releases while that channel is stable, but only in the browser, only together with the downgrade block, and only for the server's channel. The nightly job does not look at the channel at all: a policy of `all` can take a pre-release (`lib/Service/AutoUpdate/CandidateSelector.php:67-68`). And nothing lets an admin keep an app on the release lines its publisher supports for a long time.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers three rows that share one per-app channel.

| Row | Rating now | What is missing |
|---|---|---|
| `rel-prerelease` | no | No show or hide choice for pre-releases when picking a version. Safe mode hides them on a stable server channel (`src/utils/safeMode.ts:50-56`, landed 2026-09-27, after the matrix was compared), but the choice is tied to downgrades and to the server's channel, and a forge release marked pre-release by its publisher is not recognised. |
| `rel-channel` | no | No channel for the instance or per app. The server's channel is shown read-only. |
| `rel-lts-only` | no | No long-term support filter. Versions are filtered by direction and pre-release only. |

### Demand

- `rel-lts-only`: feature request, https://github.com/dependabot/dependabot-core/issues/2247. The matrix records no note for it beyond the row name: offer only long-term support releases as update targets.
- `rel-prerelease` and `rel-channel`: no demand row. Both are in the product's core area (releases).

### Competitors rated yes (evidence quoted from the matrix)

- `rel-prerelease`, Renovate rated yes: "lib/config/options/index.ts:1918 ignoreUnstable (default true) hides unstable versions; opting in or following a pre-release line is per package via packageRules; nextcloud nightlies are marked unstable (nextcloud/index.ts:70)."
- `rel-channel`, Nextcloud rated yes: "Admin UI channel selector stable/beta/enterprise (apps/updatenotification/lib/Controller/AdminController.php:46, apps/updatenotification/src/components/UpdateNotification.vue:111-113) which also governs app pre-releases (AppFetcher.php:66). Instance-wide only, not per app." Renovate rated yes: "lib/config/options/index.ts:1933 followTag makes a package follow a release tag such as next or beta, settable per package through packageRules or for the whole repository."
- `rel-lts-only`, no competitor rated yes. Nextcloud is partial: "For the server core, the Enterprise channel offers the latest patch level and delays majors, but it is customer-only ... Apps have no long-term support filter." Renovate is partial: "The node versioning treats a release as stable only once its major line has reached LTS ... this holds for node versioning only, not as a general option."

## What changes

- Every version in a version list says whether it is a pre-release. A version is a pre-release when its number carries a suffix such as `-beta.1`, when its forge release is marked pre-release, or when the App Store marks it as a nightly.
- An admin picks a channel for the instance and, where needed, per app: follow the server's update channel (the default, and today's behaviour), stable, beta, or long-term support. Versioniq never changes the server's own update channel.
- The version picker shows only versions on the app's channel, with a "Show all versions" switch that reveals the rest, each marked "Pre-release" or "Not long-term support". Safe mode keeps blocking downgrades, and its pre-release rule reads the app's channel instead of the server's.
- The nightly job only takes versions on the app's channel. On a stable channel a policy of `all` no longer takes a pre-release.
- For the long-term support channel an admin marks which release lines of an app are long-term support. A forge release whose name or tag carries the word LTS is marked too. Only versions on those lines are offered as update targets.
- `occ versioniq:versions` prints a channel column, and `occ versioniq:channel` reads and sets the channel.

## Scope

In scope: the pre-release flag on version entries, the channel store and rules, the picker switch, safe mode reading the app channel, the nightly job filter, the long-term support lines, the command, tests.

Out of scope:
- Changing the server's update channel. Nextcloud's own update notification page does that; Versioniq only reads it.
- The pending-updates sweep. `inventory-pending-updates` counts plain versions only; its sweep reads the channel rule from this change once both have landed (task 4.2).
- A release date or a minimum age. `releases-version-facts` and `releases-minimum-age` specify them.

## Impact

- New: `lib/Service/Channel/ReleaseChannel.php`, `lib/Service/Channel/ChannelStore.php`, `lib/Command/SetChannel.php`, `src/components/ChannelSelector.vue`.
- Changed: `lib/Service/Source/AppStoreSource.php` (`normalizeVersions` reads the nightly flag), `lib/Service/Source/ForgeReleaseSource.php` (`listVersions` reads the pre-release flag), `lib/Service/InstallerService.php` (`getAppVersions` stamps `preRelease`, `lts` and `onChannel`), `lib/BackgroundJob/AutoUpdateJob.php` (passes only on-channel versions), `lib/Controller/ApiController.php` (channel routes), `lib/Command/ListVersions.php` (column), `appinfo/info.xml` (command), `src/App.vue` (picker switch, instance default), `src/utils/safeMode.ts`, `l10n/en` and `l10n/nl`.
- New capability spec `release-channels`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; reading and setting a channel fits there.

## Rollback

Revert the change. The channel lives in app config keys `channel.default`, `channel.{appId}` and `lts_lines.{appId}`. Nothing else reads them, and `occ config:app:delete` removes them. Without them every app follows the server's channel again, as today.
