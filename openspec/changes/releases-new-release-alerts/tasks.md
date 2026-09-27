# releases-new-release-alerts tasks

## 1. Detection and log

- [ ] 1.1 Add `lib/Service/ReleaseAlert/ReleaseEventLog.php` and `lib/Service/ReleaseAlert/ReleaseEventDetector.php` (design D1), and call the detector after `AvailabilityResultStore::save()` in the sweep of `inventory-pending-updates`. Verify: `tests/unit/Service/ReleaseAlert/ReleaseEventDetectorTest.php` covers a higher newest version, an equal one, a lower one, an app with an error, an app new to the snapshot, the first snapshot, and the 200-entry cap.
- [ ] 1.2 Add `lib/Service/ReleaseAlert/FollowList.php`, `POST /api/follow` and `DELETE /api/follow/{appId}` (admin-only, password-confirmed, audited), and include followed apps that are not installed in the availability sweep (design D3). Verify: `tests/unit/Service/ReleaseAlert/FollowListTest.php`; the `AvailabilityServiceTest` of `inventory-pending-updates` gains a followed, not installed app.

## 2. Notification and feed

- [ ] 2.1 Add `lib/Service/ReleaseAlert/ReleaseNotifier.php`, the `release_alerts.scope` setting, and the `new_release` subject in `Notifier::prepare()` (design D2). Verify: `tests/unit/Service/ReleaseAlert/ReleaseNotifierTest.php` covers each scope with a forge app, an App Store app and a followed app; `tests/unit/Notification/NotifierTest.php` renders both texts.
- [ ] 2.2 Add `lib/Controller/FeedController.php` with the RSS route (design D4). Verify: `tests/unit/Controller/FeedControllerTest.php` asserts 403 for a non-admin, valid RSS 2.0 for an admin, and `pubDate` from `releasedAt` or `detectedAt`; extend `tests/e2e/shell.spec.ts` to fetch the feed with an app password.

## 3. Webhooks

- [ ] 3.1 Add `lib/Service/Alert/WebhookTargetStore.php` and `lib/Controller/AlertController.php` with the target routes and the test route (design D5). Verify: `tests/unit/Controller/AlertControllerTest.php` asserts 403, 400 on http, no secret in any response, and one audit row per change.
- [ ] 3.2 Add `lib/Service/Alert/AlertDispatcher.php`, `lib/Service/Alert/WebhookSender.php` and `lib/BackgroundJob/WebhookDeliveryJob.php` (design D6), and call the dispatcher from `AutoUpdateNotifier`, `PinDriftHandler`, `AdvisoryNotifier`, `AdvisoryDigestNotifier`, `PatExpiryNotifier` and `ReleaseNotifier`. Verify: `tests/unit/Service/Alert/WebhookSenderTest.php` checks both formats and the signature against a known vector; `tests/unit/BackgroundJob/WebhookDeliveryJobTest.php` covers success, a retry, and failure after the third attempt; each notifier test asserts one dispatch per alert.

## 4. Page

- [ ] 4.1 Add `src/components/AlertsPanel.vue` as an Alerts tab (scope, followed apps, targets with their last delivery and Send test), and "Follow releases" in `src/components/DiscoverPanel.vue`. Verify: `src/components/AlertsPanel.spec.ts` covers each part; `src/components/DiscoverPanel.spec.ts` covers Follow.
- [ ] 4.2 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 4.3 Add `tests/e2e/alerts.spec.ts`: the forge fixture publishes a newer release, the sweep runs with `occ background-job:execute --force-execute`, and the admin sees the notification and the feed item; a webhook target stored with `occ config:app:set versioniq webhook.fixture` (the form accepts https only; `tests/e2e/fixtures/forge/bootstrap.sh` sets the http forge addresses the same way) points at a new route on `tests/e2e/fixtures/forge/server.mjs`, which records the `new_release` delivery for the test to read.

## 5. Close

- [ ] 5.1 Set the matrix rows `rel-new-release-notify`, `rel-subscribe-feed` and `adm-webhook` to `built` with evidence lines, then archive this change.
