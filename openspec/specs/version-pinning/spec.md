---
status: implemented
---

# Version Pinning Specification

**Status**: implemented
**Standards**: OCP\App\Events\AppUpdateEvent (NC ≥ 27), OCP\App\IAppManager, OCP\Notification\IManager, OCP\BackgroundJob\TimedJob, OCP\IAppConfig
**Feature tier**: MVP

## Purpose

Pinning holds an installed app at a chosen version. Because Nextcloud core offers no cancellable pre-update hook (`OCP\App\Events\AppUpdateEvent` is post-hoc only), a pin is **enforced inside Versioniq and monitored everywhere else**: Versioniq's own install path refuses to overwrite a pin without an explicit override, while drift caused by any other update path (NC web updater, `occ app:update`, manual file replacement) is detected, audited, notified, and one-click reversible via re-pin. The UI states this trust model explicitly.

## Requirements

### Requirement: Pin an installed app to its current version [MVP]

The system MUST let an admin pin an app to its **currently installed** version via `PUT /api/app/{appId}/pin`, with password confirmation. The pin MUST be persisted under app config key `pin.{appId}` as JSON containing `version`, `pinnedBy`, `pinnedAt` (ISO-8601 UTC), and an optional `reason`. Pinning a version other than the installed one MUST be rejected; the install endpoint MAY accept `pin: true` to install-then-pin atomically (pin written only after a successful install).

#### Scenario: Pin the running version

@e2e tests/e2e/pinning.spec.ts

- GIVEN `openregister` is installed at 2.3.0
- WHEN admin `alice` calls `PUT /api/app/openregister/pin` with `{reason: "2.5.0 breaks LDAP sync"}` and confirms her password
- THEN `versioniq.pin.openregister` MUST be set to `{version: "2.3.0", pinnedBy: "alice", pinnedAt: ISO-8601, reason: "2.5.0 breaks LDAP sync"}`
- AND the response MUST echo the pin record

#### Scenario: Pin requires password confirmation

@e2e exclude the e2e admin session is always password-confirmed; the un-confirmed path is enforced by the PasswordConfirmationRequired attribute (route-auth gate).

- GIVEN an admin session without recent password confirmation
- WHEN `PUT /api/app/openregister/pin` is called
- THEN the request MUST be rejected by the `PasswordConfirmationRequired` mechanism
- AND no pin MUST be written

#### Scenario: Install-then-pin

@e2e exclude occ install has no --pin flag and the pin:true API param requires a real web install that 503s the mod_php test image; the atomic install-then-pin is unit-tested.

- GIVEN `openregister` is installed at 2.5.0
- WHEN the admin installs 2.3.0 with `pin: true` and the install succeeds
- THEN `pin.openregister` MUST record version 2.3.0
- AND if the install fails, no pin MUST be written

#### Scenario: Non-admin is blocked

@e2e tests/e2e/shell.spec.ts

- GIVEN a non-admin authenticated user
- WHEN they call any pin endpoint
- THEN the system MUST respond with HTTP 403
- AND no pin state MUST change

---

### Requirement: Unpin [MVP]

The system MUST let an admin remove a pin via `DELETE /api/app/{appId}/pin`, with password confirmation. Unpinning MUST NOT change the installed version.

#### Scenario: Unpin clears the record

@e2e tests/e2e/pinning.spec.ts

- GIVEN `pin.openregister` exists for version 2.3.0
- WHEN the admin calls `DELETE /api/app/openregister/pin` and confirms their password
- THEN `versioniq.pin.openregister` MUST be removed
- AND `openregister` MUST remain installed at its current version

---

### Requirement: Pins are enforced on Versioniq's own install path [MVP]

When an app is pinned, `installVersion` for any version other than the pinned one MUST fail with HTTP 409 naming the pinned version, unless the request carries an explicit `overridePin` parameter with value `repin` or `unpin`. With `overridePin=repin` the pin MUST be moved to the newly installed version; with `overridePin=unpin` the pin MUST be removed. In both override cases the pin state MUST only change if the install succeeds, and the pin MUST be adjusted such that the subsequent `AppUpdateEvent` for this install does not register as drift.

#### Scenario: Install over a pin is rejected

@e2e tests/e2e/pinning-guards.spec.ts

- GIVEN `openregister` is pinned at 2.3.0
- WHEN an admin calls `installVersion('openregister', '2.5.0')` without `overridePin`
- THEN the system MUST respond HTTP 409 with a message naming the pinned version 2.3.0
- AND no download or filesystem change MUST happen

#### Scenario: Override with re-pin

@e2e exclude occ install exposes no --override-pin flag and overridePin via a web install 503s the test image; the override path is unit-tested.

- GIVEN `openregister` is pinned at 2.3.0
- WHEN the admin installs 2.5.0 with `overridePin=repin` and the install succeeds
- THEN `pin.openregister` MUST now record version 2.5.0
- AND no drift notification MUST be produced for this install

#### Scenario: Override with unpin

@e2e exclude same as override-with-re-pin — the overridePin=unpin path is unit-tested.

- GIVEN `openregister` is pinned at 2.3.0
- WHEN the admin installs 2.5.0 with `overridePin=unpin` and the install succeeds
- THEN `pin.openregister` MUST be removed

#### Scenario: Reinstalling the pinned version needs no override

@e2e tests/e2e/pinning-guards.spec.ts

- GIVEN `openregister` is pinned at 2.3.0
- WHEN the admin installs 2.3.0 (re-pin / repair reinstall)
- THEN the install MUST proceed without `overridePin`
- AND the pin MUST remain at 2.3.0

---

### Requirement: Drift detection [MVP]

The system MUST detect when a pinned app's installed version no longer matches its pin. Detection MUST happen (a) immediately via a listener on `OCP\App\Events\AppUpdateEvent`, and (b) at least daily via a reconciliation background job comparing every pin against `IAppManager` versions (catching updates that bypassed the event, e.g. performed while Versioniq was disabled). Detected drift MUST be recorded on the pin (`driftedTo`, `driftedAt`) and MUST be acted on only once per drifted version (idempotent).

#### Scenario: NC updater updates a pinned app

@e2e exclude a pin is monitored, not enforced against NC core; an out-of-band core update is not reproducible in e2e.

- GIVEN `openregister` is pinned at 2.3.0
- WHEN an admin updates it to 2.5.0 via Nextcloud's regular Apps page
- THEN the `AppUpdateEvent` listener MUST detect that the installed version 2.5.0 differs from the pin
- AND the pin record MUST be updated with `driftedTo: "2.5.0"` and a `driftedAt` timestamp

#### Scenario: Reconciliation job catches missed drift

@e2e tests/e2e/jobs.spec.ts

- GIVEN `openregister` is pinned at 2.3.0 and was updated to 2.5.0 while Versioniq was disabled
- WHEN the daily reconciliation job runs
- THEN the drift MUST be detected and recorded exactly as in the listener path

#### Scenario: No drift while versions match

@e2e tests/e2e/jobs.spec.ts

- GIVEN `openregister` is pinned at 2.3.0 and installed at 2.3.0
- WHEN the reconciliation job runs
- THEN no drift MUST be recorded and no notification MUST be sent

#### Scenario: Drift handled once per version

@e2e exclude drift dedup in the listener/reconcile path is unit-tested.

- GIVEN drift to 2.5.0 was already recorded and notified
- WHEN the reconciliation job runs again with the app still at 2.5.0
- THEN no additional notification MUST be sent

---

### Requirement: Drift response — notify and offer re-pin [MVP]

On newly detected drift the system MUST notify admin-group members via `OCP\Notification\IManager` (naming app, pinned version, and observed version) and the UI MUST show a drift state on the affected app offering **Re-pin** (reinstall the pinned version through the normal install flow, including password confirmation and source/integrity checks) and **Accept** (move the pin to the observed version, or remove it). The system MUST NOT reinstall anything autonomously.

#### Scenario: Admins are notified

@e2e exclude drift notifications are raised by the reconcile job; unit-tested.

- GIVEN drift of `openregister` from pinned 2.3.0 to installed 2.5.0 is newly detected
- WHEN the drift handler runs
- THEN every member of the `admin` group MUST receive a Nextcloud notification naming `openregister`, 2.3.0, and 2.5.0
- AND the notification MUST link into Versioniq

#### Scenario: Re-pin reinstalls the pinned version

@e2e exclude the drift-banner Re-pin action requires reproducing out-of-band drift; unit-tested.

- GIVEN the UI shows the drift banner for `openregister` (pinned 2.3.0, installed 2.5.0)
- WHEN the admin clicks Re-pin and confirms their password
- THEN the system MUST install 2.3.0 via the existing install path (bound source, allowlist, integrity checks all apply)
- AND on success the pin MUST remain at 2.3.0 with the drift markers cleared

#### Scenario: Re-pin to an older version confirms the downgrade

@e2e exclude reproducing out-of-band upward drift is not possible in a browser run; the flow is unit-tested in src/utils/repin.spec.ts.

- GIVEN the drift banner for `openregister` (pinned 2.3.0, installed 2.5.0 after Nextcloud's updater ran)
- WHEN the admin clicks Re-pin
- THEN the UI MUST open the downgrade confirmation with its migration preview
- AND on confirm the install request MUST carry `allowDowngrade`, so the server-side downgrade guard does not refuse it
- AND on cancel nothing MUST be installed

#### Scenario: Accept the new version

@e2e exclude the drift-banner Accept action requires reproducing drift; unit-tested.

- GIVEN the drift banner for `openregister` (pinned 2.3.0, installed 2.5.0)
- WHEN the admin chooses Accept → "move pin to 2.5.0"
- THEN `pin.openregister` MUST record version 2.5.0 with cleared drift markers
- AND choosing Accept → "remove pin" MUST delete the pin record instead

#### Scenario: No autonomous reinstall

@e2e exclude the app never reinstalls autonomously (monitored-not-enforced); asserted in reconcile-job unit tests.

- GIVEN drift is detected outside any admin session (cron)
- WHEN the drift handler completes
- THEN the installed version MUST be unchanged (notification and record only)

---

### Requirement: Honest pin presentation [MVP]

Pinned apps MUST be visibly badged in the app list and version picker (pinned version, who, when, reason). The pin UI MUST state that pins are enforced inside Versioniq and monitored elsewhere — Nextcloud's own updater can still update the app, in which case the admin is notified. `GET /api/pins` MUST list all pins joined with the live installed version and current drift status.

#### Scenario: Pinned badge

@e2e tests/e2e/pinning.spec.ts

- GIVEN `openregister` is pinned at 2.3.0
- WHEN the admin views the app list
- THEN the `openregister` card MUST show a pin badge with version 2.3.0
- AND the badge detail MUST show pinnedBy, pinnedAt, and reason

#### Scenario: Trust model is stated at pin time

@e2e tests/e2e/pinning.spec.ts

- GIVEN an admin opens the pin dialog
- WHEN the dialog renders
- THEN it MUST contain the explanation that the pin does not block Nextcloud's own updater and that drift triggers a notification

#### Scenario: List pins with live status

@e2e tests/e2e/pinning-guards.spec.ts

- GIVEN pins exist for `openregister` (no drift) and `calendar` (drifted)
- WHEN the admin calls `GET /api/pins`
- THEN the response MUST contain both pins with their installed versions
- AND `calendar` MUST carry its drift state (`driftedTo`, `driftedAt`)

## User Stories

1. As a Nextcloud admin, I want to pin an app after rolling it back so that Versioniq never lets me (or a colleague using Versioniq) accidentally overwrite the rollback.
2. As an on-call admin, I want to be notified when anything else updates a pinned app so that a silent updater click doesn't reintroduce the bug I rolled back from.
3. As a Nextcloud admin, I want one-click re-pin so that recovering from unwanted drift is as easy as causing it was.
4. As a cautious admin, I want the UI to tell me exactly what a pin does and does not guarantee.

## Acceptance Criteria

- [ ] `PUT`/`DELETE /api/app/{appId}/pin` with `PasswordConfirmationRequired`; admin-only; pin restricted to installed version
- [ ] Pin persisted as `pin.{appId}` JSON via `IAppConfig` (SourceBinding-style value object)
- [ ] `installVersion` returns 409 on pinned apps without `overridePin`; `repin`/`unpin` semantics implemented; pin changes only on install success
- [ ] `AppUpdateEvent` listener + daily reconciliation `TimedJob` detect drift; idempotent per drifted version
- [ ] Drift notifies admin group via `OCP\Notification\IManager` with registered `Notifier`
- [ ] UI: pin/unpin actions, badge, drift banner with Re-pin / Accept, honest trust-model copy (English i18n source keys)
- [ ] Audit entries `pin` / `unpin` / `pin_drift` written (see audit-trail delta)
- [ ] `composer check:strict` passes; PHPUnit suite passes

## Notes

- **Investigated and confirmed:** NC core has no cancellable pre-update hook; `OCP\App\Events\AppUpdateEvent` (dispatched post-update in `OC\App\AppManager`) is detection-only. If a future NC release adds a veto mechanism, enforcement can be upgraded without changing this capability's surface.
- The reconciliation job is registered via `<background-jobs>` in `appinfo/info.xml`; the `Notifier` via `IRegistrationContext::registerNotifierService` (there is no `IRegistrationContext::registerJob` — known fleet gotcha).
- Pins live in app config, not a table — one per app maximum, same durability class as source bindings.
