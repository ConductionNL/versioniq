# 5. Version pins are enforced by Versioniq and monitored elsewhere

- Status: Accepted
- Date: 2026-07-23

## Context

Administrators want to hold an app at a version. Nextcloud has no cancellable pre-update hook and no per-app update hold. `OCP\App\Events\AppUpdateEvent` is dispatched only after an update has happened, so it can detect a change but not stop it.

## Decision

- A pin is self-enforcement plus monitored drift, never a block on the Nextcloud updater, and the UI says so.
- Inside Versioniq, installing a different version of a pinned app is refused (HTTP 409) unless the request carries an explicit `overridePin` of `repin` or `unpin`, so the pin state after the install is always intentional.
- Detect drift in two ways: `AppUpdatedListener` on `AppUpdateEvent` for updates that go through the app manager, and the daily `PinReconcileJob` for everything the listener misses. Drift notifies administrators and is audited; nothing is installed automatically.
- Store a pin as a JSON app config value per app, not in a database table.

## Consequences

- Versioniq never overwrites a pin by accident, and scripted callers have to state what should happen to the pin.
- Another tool can still update a pinned app; administrators are told afterwards, not prevented beforehand.
- Pin and unpin require password confirmation like installs.

## Source

`openspec/changes/archive/2026-07-23-add-version-pinning/design.md` ("What Nextcloud actually offers", "Drift detection", "Why 409 + explicit override instead of silently allowing"); implemented in `lib/Listener/AppUpdatedListener.php`, `lib/BackgroundJob/PinReconcileJob.php`, `lib/Service/Pin/` and `lib/Service/Installer/PinChoice.php`.
