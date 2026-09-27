---
kind: code
---

# Proposal: releases-new-release-alerts

## Why

Nextcloud tells admins when an App Store app has an update. It knows nothing about an app installed from GitHub or Forgejo, so a new release of a forge-bound app goes unnoticed until someone opens its version list. Versioniq raises notifications for advisories, token expiry, pin drift and automatic updates, but never for a new version. And every alert lives in the Nextcloud bell only: a team that watches a chat channel, or a monitoring system that takes webhooks, gets nothing.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers three rows that share one alert path.

| Row | Rating now | What is missing |
|---|---|---|
| `rel-new-release-notify` | no | No notification when a new version appears. The subjects in `lib/Notification/Notifier.php:64-193` cover advisories, tokens, pin drift and automatic updates only. |
| `rel-subscribe-feed` | no | No e-mail, RSS or webhook subscription to new releases, and no way to follow an app that is not installed. |
| `adm-webhook` | no | No chat or webhook delivery for any alert. |

E-mail is not part of this change. The matrix row `adm-email` is decided-no for Versioniq and owned by nextcloud/server: the Nextcloud notifications app already mails pending notifications, so every alert this change adds to the bell reaches e-mail through that setting. The subscription therefore offers RSS and webhooks.

### Demand

No demand row for any of the three. `rel-new-release-notify` and `rel-subscribe-feed` are in the product's core area (releases), and `rel-new-release-notify` has four competitors rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- `rel-new-release-notify`, Nextcloud rated yes: "Daily background job notifies members of configurable groups of app and server updates (apps/updatenotification/lib/BackgroundJob/UpdateAvailableNotifications.php:48,146-196,206)." Renovate rated yes: "A new release produces a branch or PR, or a dashboard entry when approval is required (lib/workers/repository/dependency-dashboard.ts:500), which the forge notifies watchers about." Dependabot rated yes: "a new release (after cooldown) produces a pull request on the configured schedule ... which notifies repository watchers." Easy Updates Manager rated yes: "Premium, docs: weekly or monthly e-mail report of pending updates (https://easyupdatesmanager.com/knowledge-base/email-notification-of-updates-premium/)."
- `rel-subscribe-feed`, no competitor rated yes. Nextcloud is partial: "The app store publishes a global RSS/Atom feed of all new releases (https://apps.nextcloud.com/feeds/releases.rss, read 2026-09-26), not scoped to installed apps; no e-mail or webhook subscription in the server."
- `adm-webhook`, Dependabot rated yes: "Closed service: dependabot_alert webhook events, including assignment changes (https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-alerts); chat delivery goes through webhooks or GitHub apps." Easy Updates Manager rated yes: "Premium, docs: log alerts to Slack via webhook (https://easyupdatesmanager.com/knowledge-base/log-clearance-and-to-external-channels-premium/)."

## What changes

- After each availability sweep, Versioniq compares the newest version of every app with the previous sweep. A version that was not there before is a new release. It is logged and, by default, sent to admins as a notification for forge-bound apps. The App Store apps are left to Nextcloud's own update notification, unless an admin widens the scope to every app.
- An admin can follow an app that is not installed, from the Discover tab or by app id. Its releases are swept and announced like those of an installed app.
- `GET /apps/versioniq/feed/releases.rss` serves the release log as an RSS feed. It is admin-only: a feed reader signs in with an admin's app password, as the Nextcloud News app and most readers can.
- An admin adds webhook targets: an https address, a format (plain JSON, or the `text` message that Slack, Mattermost and Rocket.Chat incoming webhooks accept), which alert kinds to send, and an optional signing secret. Every Versioniq alert, new releases included, is sent to each matching target in a background job, signed when a secret is set. The page shows each target's last delivery and has a test button.

## Scope

In scope: new-release detection and its log, the notification and its scope setting, the follow list, the RSS feed, webhook targets and delivery for every alert kind, tests.

Out of scope:
- E-mail. Decided-no, see above.
- Posting into a Nextcloud Talk conversation. Talk bots use their own signed API; a later change can add Talk as a target.
- The availability sweep itself. `inventory-pending-updates` specifies it; this change reads its snapshot and adds followed apps to it.
- A digest of pending updates. The Apps tab of `inventory-pending-updates` already lists them.

## Impact

- New: `lib/Service/ReleaseAlert/ReleaseEventDetector.php`, `lib/Service/ReleaseAlert/ReleaseEventLog.php`, `lib/Service/ReleaseAlert/FollowList.php`, `lib/Service/ReleaseAlert/ReleaseNotifier.php`, `lib/Service/Alert/AlertDispatcher.php`, `lib/Service/Alert/WebhookTargetStore.php`, `lib/Service/Alert/WebhookSender.php`, `lib/BackgroundJob/WebhookDeliveryJob.php`, `lib/Controller/FeedController.php`, `lib/Controller/AlertController.php`, `src/components/AlertsPanel.vue`.
- Changed: `lib/Notification/Notifier.php` (subject `new_release`), the five places that raise notifications today (`AutoUpdateNotifier`, `PinDriftHandler`, `AdvisoryNotifier`, `AdvisoryDigestNotifier`, `PatExpiryNotifier`) call the dispatcher, the availability sweep of `inventory-pending-updates` (followed apps, and the detector after `save()`), `src/components/DiscoverPanel.vue` (Follow), `src/App.vue` (an Alerts tab), `l10n/en` and `l10n/nl`.
- New capability specs `release-alerts` and `alert-webhooks`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the release log is read-only data it can return.

## Rollback

Revert the change. The log, the follow list, the scope setting and the targets live in app config keys `release_alerts.*` and `webhook.*`; nothing else reads them. A queued delivery job left behind cannot load its class and does nothing; `occ background-job:delete <id>` removes it.
