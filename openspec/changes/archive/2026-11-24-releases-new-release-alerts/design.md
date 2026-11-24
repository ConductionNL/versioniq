# Design: releases-new-release-alerts

Read against `development` at 02e1050 (2026-09-27).

## Context

- `inventory-pending-updates` (open change, same PR batch) adds `AvailabilityRefreshJob`, which sweeps every installed app every 6 hours and stores, per app, `installedVersion`, `newestVersion`, `newestCompatibleVersion`, `sourceId` and `error`, through `AvailabilityResultStore::save()`, keeping the previous snapshot when a new one cannot be stored. It sweeps apps whose state is not `notInstalled`. This change reads that snapshot and adds followed apps to the sweep.
- Versioniq raises notifications in five places, each looping over the admin group: `AutoUpdateNotifier::fireAll()` (`lib/Service/AutoUpdate/AutoUpdateNotifier.php:75-102`), `PinDriftHandler::notifyAdmins()` (`lib/Service/Pin/PinDriftHandler.php:78-108`), `AdvisoryNotifier::notifyNewAdvisories()` (`lib/Service/Advisory/AdvisoryNotifier.php:54`), the weekly digest (`lib/Service/Advisory/AdvisoryDigestNotifier.php:116`) and `PatExpiryNotifier::notify()` (`lib/Service/Pat/PatExpiryNotifier.php:46`). `Notifier::prepare()` renders each subject (`lib/Notification/Notifier.php:57-196`).
- Every controller today is an `OCSController` with `#[ApiRoute]` and an explicit `isAdmin()` check (`lib/Controller/ApiController.php:1386`). An OCS route answers in the OCS envelope, which a feed reader cannot read.
- Secrets are encrypted with `ICrypto` (`lib/Service/Pat/PatManager.php:65`). Outbound calls block local addresses unless `allow_local_remote_servers` is on (`lib/Service/Source/ForgeReleaseSource.php:72`).
- Nextcloud's own `updatenotification` app already notifies about App Store app updates (matrix evidence for `rel-new-release-notify`), and the notifications app can mail every notification (row `adm-email`).

## Goals and non-goals

Goals: a notice for every new release of a followed or installed app that Nextcloud does not announce itself; the same releases as a feed; every Versioniq alert on a webhook.

Non-goals: e-mail (decided-no, `adm-email`), Talk, a public feed.

## Decisions

### D1. What a new release is

`ReleaseEventDetector::detect(previous, current)` runs right after `AvailabilityResultStore::save()`. For each app in the current snapshot without an `error`, a new release is a `newestVersion` that is higher than the previous snapshot's `newestVersion` for that app. An app missing from the previous snapshot, or the first snapshot of all, sets a baseline and raises nothing, so the first sweep after install does not announce every app at once.

Each event is appended to `ReleaseEventLog`: JSON under `release_alerts.log`, newest first, at most 200 entries, each `{appId, version, sourceId, compatible, releasedAt, detectedAt}`. `compatible` is whether the version equals `newestCompatibleVersion`; `releasedAt` comes from the version entry when `releases-version-facts` has landed, else null.

Alternative considered: compare full version lists between sweeps. Rejected. The snapshot keeps only the newest version, and an older patch published later on another line is not something admins asked to be told about.

### D2. The notification and its scope

`ReleaseNotifier` sends subject `new_release` (`{app, version, sourceId, compatible}`) to every admin, linking to the Versioniq admin page. `Notifier::prepare()` renders "New version of {app}: {version}" and, when `compatible` is false, adds "It does not run on this server version."

`release_alerts.scope`: `forge` (the default: apps whose source is not the App Store, and followed apps), `all`, or `off`. The default leaves App Store apps to Nextcloud's own update notification, so an admin does not get the same news twice.

Alternative considered: announce every app by default. Rejected for the duplicate. The setting is one click away for an admin who switched Nextcloud's own notice off.

### D3. Following an app that is not installed

`FollowList` keeps `release_alerts.follow` as a JSON list of `{appId, sourceId, addedBy, addedAt}`. The Discover tab gets a "Follow releases" action on a hit; the Alerts tab lists followed apps with an Unfollow action. `POST /api/follow` and `DELETE /api/follow/{appId}` are admin-only, password-confirmed and audited as `settings`.

The availability sweep adds every followed app that is not installed, with `installedVersion` null and the source from the follow entry. Such an app gets no "behind" count; it only feeds the detector.

### D4. The feed

`FeedController extends Controller` (not OCS) with `#[FrontpageRoute(verb: 'GET', url: '/feed/releases.rss')]` and `#[NoCSRFRequired]`, and no `#[NoAdminRequired]`, so the framework requires an admin, and the method checks `isAdmin()` again like every other route. It returns RSS 2.0 (`application/rss+xml`) built with `XMLWriter`: one item per log entry, title "{app} {version}", a link to the Versioniq admin page, the source id, and `pubDate` from `releasedAt` or else `detectedAt`. A reader signs in with Basic authentication and an admin's app password.

Alternative considered: a secret feed address that needs no sign-in. Rejected. It would be the only endpoint of this admin-only app that answers without an admin, and it would disclose which apps and versions an instance runs to anyone who sees the address.

### D5. Webhook targets

`WebhookTargetStore` keeps `webhook.{id}` JSON: `name`, `url` (https only, the `InstanceSettings` URL checks), `format` (`json` or `chat`), `kinds` (any of `new_release`, `security`, `auto_update`, `pin_drift`, `token`), `encryptedSecret` (optional, `ICrypto`), `enabled`, and the last delivery (`at`, `status`, `error`) under `webhook.{id}.last`. Routes on `AlertController`: `GET/POST /api/webhooks`, `PUT/DELETE /api/webhooks/{id}` and `POST /api/webhooks/{id}/test`, admin-only, writes password-confirmed and audited as `settings`. The secret is never returned.

### D6. Dispatch and delivery

`AlertDispatcher::dispatch(string $kind, string $subject, array $parameters, string $text)` is called by each of the five notifiers and by `ReleaseNotifier`, after their notification loop. It does not send anything itself: for every enabled target whose `kinds` include `$kind` it adds a `WebhookDeliveryJob` (`QueuedJob`) with the target id and the payload. So a slow or dead endpoint never delays the nightly job, the advisory sweep or a request.

`WebhookSender` posts with a 10 s timeout and `allow_local_address` from `allow_local_remote_servers`:

- `json`: `{"app": "versioniq", "kind", "subject", "parameters", "text", "instance", "sentAt"}`.
- `chat`: `{"text": "<the rendered English text>"}`, the shape Slack, Mattermost and Rocket.Chat incoming webhooks accept.

With a secret, it adds `X-Versioniq-Timestamp` and `X-Versioniq-Signature: sha256=<hex HMAC-SHA256 of timestamp.body>`. A 2xx answer is success. Otherwise the job re-queues itself up to three attempts in all, then records the failure on the target and stops.

Alternative considered: send inline from each notifier. Rejected. The nightly job and the advisory sweep run on a time budget, and a webhook endpoint that hangs would eat it.

## Risks and trade-offs

- [A webhook payload leaves the instance with app ids and versions] → only targets an admin added receive it, over https, optionally signed, and the kinds are chosen per target.
- [The detector misses a release that came and went between two sweeps] → acceptable: a release pulled within six hours is not one to install.
- [A followed app that the sweep cannot reach] → its `error` suppresses detection for that sweep, the Alerts tab shows the error, and the next good sweep compares against the last good one.
- [The chat text is rendered in English] → the webhook has no recipient language; the bell notification stays localised per admin.

## Migration

No schema change. The first sweep after upgrade sets the baseline and announces nothing. No target exists until an admin adds one.
