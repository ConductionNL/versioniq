---
status: implemented
---

# Audit Trail Specification

**Status**: implemented
**OpenSpec changes**: [audit-security-reports](../../changes/archive/2026-11-24-audit-security-reports/)
**Standards**: OCP\AppFramework\Db\QBMapper, OCP\BackgroundJob\TimedJob, OCP\IAppConfig
**Feature tier**: MVP

## Purpose

The audit trail records every version operation Versioniq performs — installs (App Store and external, success and failure) and source-binding changes — with who, what, and when. It backs the explicit `docs/intro.md` promise ("Every install, downgrade, or pin is logged with who, what, and when") with a dedicated, immutable, retention-managed table that admins can query without digging through server logs.

## Requirements

### Requirement: Version operations are recorded [MVP]

The system MUST write one audit entry for every install operation executed through Versioniq, on both the success and the failure path. Each entry MUST record: actor uid, app id, operation (`install`), version before (`from_version`), requested version (`to_version`), canonical source id, status (`success`/`failure`), an optional message, and a UTC timestamp. The audit write MUST be best-effort: a failed audit insert MUST NOT fail, abort, or roll back the install, and MUST be logged via `LoggerInterface`.

#### Scenario: Successful App Store install is recorded

@e2e tests/e2e/audit.spec.ts

- GIVEN admin `alice` has `openregister@2.5.0` installed
- WHEN she installs `openregister@2.3.0` from the App Store via Versioniq and the install succeeds
- THEN one audit entry MUST exist with `actor_uid=alice`, `app_id=openregister`, `operation=install`, `from_version=2.5.0`, `to_version=2.3.0`, `source_id=appstore`, `status=success`
- AND `created_at` MUST be the operation time in UTC

#### Scenario: Failed install is recorded with the failure reason

@e2e exclude driving a failed install to assert its audit row is covered by AuditLogger unit tests; the forge fail-closed path is e2e-covered separately.

- GIVEN a download error occurs during an install attempt (e.g. HTTP 404 on the artifact)
- WHEN the install fails
- THEN one audit entry MUST exist with `status=failure`
- AND `message` MUST contain the same actionable error text returned to the admin
- AND the entry MUST be written even though the installer raised an error

#### Scenario: External install records source and integrity warning

@e2e exclude the integrity-warning branch needs a sibling-less external install asserted at the audit layer; the AuditLogger write is unit-tested.

- GIVEN admin `alice` installs `openregister@2.5.0` from `github:ConductionNL/openregister` and no `.sha256` sibling asset exists
- WHEN the install succeeds with `integrityWarning: "No SHA-256 checksum available for this artifact."`
- THEN the audit entry MUST record `source_id=github:ConductionNL/openregister` and `status=success`
- AND `message` MUST contain the integrity warning text

#### Scenario: Audit write failure does not break the install

@e2e exclude requires injecting an audit-store write failure; the best-effort write path is unit-tested.

- GIVEN the audit table is unavailable (e.g. migration not yet run)
- WHEN an admin installs a version and the install itself succeeds
- THEN the install response MUST report success
- AND the audit failure MUST be logged via `LoggerInterface::error`
- AND no exception from the audit path MUST reach the API response

#### Scenario: No secrets in audit entries

@e2e exclude entries never carry token material by construction; asserted in AuditLogger unit tests.

- GIVEN an external install for a private repo authenticates with a stored PAT
- WHEN the audit entry is written (success or failure)
- THEN neither the `message` nor any other column MUST contain the PAT value, an `Authorization` header, or any other secret

---

### Requirement: Source binding changes are recorded [MVP]

The system MUST write an audit entry with `operation=bind_source` whenever a source binding is created or overwritten — both via the explicit `POST /api/source/{appId}/bind` endpoint and via the implicit binding performed by an install.

#### Scenario: Explicit bind is recorded

@e2e exclude the bind_source audit row is unit-tested; e2e covers binding via re-bind without asserting the row.

- GIVEN admin `alice` calls `POST /api/source/openregister/bind` with `{kind: "github-release", owner: "ConductionNL", repo: "openregister"}`
- WHEN the binding is persisted
- THEN one audit entry MUST exist with `actor_uid=alice`, `app_id=openregister`, `operation=bind_source`, `source_id=github:ConductionNL/openregister`, `status=success`

#### Scenario: Rebinding records the previous source

@e2e exclude the previous-source capture on rebind is unit-tested in AuditLogger.

- GIVEN `openregister` is bound to `github:ConductionNL/openregister`
- WHEN an admin rebinds it to the App Store
- THEN the audit entry MUST record `source_id=appstore`
- AND `message` MUST name the previous source id `github:ConductionNL/openregister`

---

### Requirement: Audit entries are immutable and admin-readable [MVP]

The system MUST expose audit entries through `GET /api/audit` — admin-only, paginated (default 50, maximum 200 per page), newest-first, filterable by `appId`. The system MUST NOT expose any endpoint that updates or deletes audit entries; the retention prune job is the only deletion path.

#### Scenario: Admin lists the audit log

@e2e tests/e2e/audit.spec.ts

- GIVEN 75 audit entries exist
- WHEN an admin calls `GET /api/audit`
- THEN the response MUST contain the 50 newest entries, newest-first
- AND `GET /api/audit?offset=50` MUST return the remaining 25

#### Scenario: Filter by app

@e2e tests/e2e/audit.spec.ts

- GIVEN audit entries exist for `openregister` and `calendar`
- WHEN an admin calls `GET /api/audit?appId=openregister`
- THEN every returned entry MUST have `app_id=openregister`

#### Scenario: Non-admin is blocked

@e2e tests/e2e/audit.spec.ts

- GIVEN a non-admin authenticated user
- WHEN they call `GET /api/audit`
- THEN the system MUST respond with HTTP 403
- AND no audit data MUST be returned

#### Scenario: No mutation endpoints exist

@e2e tests/e2e/audit.spec.ts

- GIVEN any authenticated user, including an admin
- WHEN they attempt `PUT`/`PATCH`/`DELETE` against `/api/audit` or `/api/audit/{id}`
- THEN the router MUST NOT expose such a route (404)
- AND no code path outside the retention prune job MUST modify or delete audit rows

---

### Requirement: Audit history UI [MVP]

The system MUST present the audit trail in the admin UI: a global history view and a per-app history tab in the version picker. Each row MUST show timestamp, actor, app, operation, from→to versions, source, and status; failed operations MUST be visually distinct using theme CSS variables (no hardcoded colors).

#### Scenario: Global history view

@e2e tests/e2e/audit.spec.ts

@e2e tests/e2e/panels.spec.ts

- GIVEN audit entries exist for multiple apps
- WHEN the admin opens the History section
- THEN entries MUST be listed newest-first across all apps
- AND each row MUST show when, who, app, operation, from→to, source, and status
- AND additional pages MUST be loadable without a full page reload

#### Scenario: Per-app history tab

@e2e tests/e2e/panels.spec.ts

- GIVEN the admin opened the version picker for `openregister`
- WHEN they switch to the History tab
- THEN only entries with `app_id=openregister` MUST be shown
- AND a rollback entry (from 2.5.0 to 2.3.0) MUST be readable as a downgrade at a glance

---

### Requirement: Retention [MVP]

The system MUST prune audit entries older than `versioniq.audit_retention_days` (default 365, minimum 30 — lower configured values MUST be clamped to 30) via a daily background job. Entries within the window MUST NOT be pruned by count.

#### Scenario: Old entries are pruned

@e2e exclude retention pruning runs in a daily TimedJob over aged rows; time cannot be advanced in e2e — unit-tested.

- GIVEN `audit_retention_days` is unset (default 365)
- AND an audit entry is 400 days old
- WHEN the daily prune job runs
- THEN that entry MUST be deleted
- AND entries newer than 365 days MUST remain

#### Scenario: Retention floor is enforced

@e2e exclude the retention floor is a config-bounded prune rule, unit-tested.

- GIVEN an admin sets `audit_retention_days` to `7`
- WHEN the prune job runs
- THEN entries newer than 30 days MUST NOT be deleted
- AND the clamping MUST be logged

---

### Requirement: Instance settings on a page [MVP]

The admin page MUST let an administrator set, without `occ`, the audit retention
(`audit_retention_days`, 30 to 3650), the number of cached versions per app
(`artifact_cache_keep`, 0 to 20) and the base URLs that were app-config only: the App Store
API (`appstore.api_base`, a store mirror), GitHub (`forge.github.api_base` and `web_base`,
GitHub Enterprise, https only because tokens are sent there) and the advisory feed
(`advisory.feed_base`, an internal mirror). `GET /api/instance-settings` returns each override
next to its default; `PUT /api/instance-settings` (admin-only, password-confirmed) validates
every field before writing any, writes the keys and types the readers use, clears an override
on a blank URL, and records a `settings` audit entry.

#### Scenario: An admin sets audit retention from the page

@e2e tests/e2e/panels.spec.ts

- **GIVEN** the admin opens the Settings tab
- **WHEN** they set history retention to 90 days and save
- **THEN** `audit_retention_days` MUST be stored as the integer 90, which the prune job reads
- **AND** a value below 30 MUST be refused with HTTP 400 and nothing stored

#### Scenario: An admin points GitHub at GitHub Enterprise

@e2e exclude a GitHub Enterprise host cannot be reached from CI; covered by tests/unit/Service/Settings/InstanceSettingsTest.php and src/components/InstanceSettingsPanel.spec.ts.

- **GIVEN** the admin enters `https://ghe.example.org/api/v3` as the GitHub API address
- **WHEN** they save
- **THEN** `forge.github.api_base` MUST hold that address
- **AND** an `http://` address MUST be refused, since tokens are sent to it

### Requirement: Pin lifecycle operations are audited [MVP]

The system MUST write audit entries for pin operations: `pin` (on pin creation, including install-then-pin and `overridePin=repin`), `unpin` (on pin removal, including `overridePin=unpin` and Accept→remove), and `pin_drift` (on newly detected drift, with `actor_uid=system`, `from_version` = pinned version, `to_version` = observed version). All writes follow the audit capability's best-effort rule.

#### Scenario: Pin is audited

@e2e tests/e2e/pinning.spec.ts

- GIVEN admin `alice` pins `openregister` at 2.3.0
- WHEN the pin is persisted
- THEN one audit entry MUST exist with `actor_uid=alice`, `app_id=openregister`, `operation=pin`, `to_version=2.3.0`, `status=success`

#### Scenario: Unpin is audited

@e2e tests/e2e/pinning.spec.ts

- GIVEN `openregister` is pinned at 2.3.0
- WHEN admin `alice` unpins it
- THEN one audit entry MUST exist with `operation=unpin`, `from_version=2.3.0`, `status=success`

#### Scenario: Drift is audited as system action

@e2e exclude drift requires the NC updater to change a pinned app out-of-band; not reproducible in e2e (pinning is monitored-not-enforced) — unit-tested.

- GIVEN `openregister` pinned at 2.3.0 drifts to 2.5.0 via Nextcloud's own updater
- WHEN the drift handler records the drift
- THEN one audit entry MUST exist with `actor_uid=system`, `operation=pin_drift`, `from_version=2.3.0`, `to_version=2.5.0`
- AND repeated reconcile runs for the same drifted version MUST NOT create additional `pin_drift` entries

### Requirement: Trusted-source changes and cleared bindings are recorded

Adding a trusted-source pattern MUST write an audit entry with operation `trust_source` naming the pattern and the admin. Removing one MUST write `untrust_source`. Clearing an app's source binding MUST write `unbind_source` naming the app and the source it was bound to. The History tab MUST show these entries with the others, for example "Trusted source added: codeberg.org/zuiddrecht".

#### Scenario: An admin adds a trusted source

- **WHEN** an admin adds the pattern `codeberg.org/zuiddrecht/*` and confirms their password
- **THEN** the audit trail MUST hold a `trust_source` entry with that pattern and the admin's user id
- **AND** the History tab MUST show "Trusted source added: codeberg.org/zuiddrecht"

#### Scenario: An admin clears a binding

- **GIVEN** Dossiq is bound to GitHub `ConductionNL/dossiq`
- **WHEN** an admin clears the binding
- **THEN** the audit trail MUST hold an `unbind_source` entry naming Dossiq and `github:ConductionNL/dossiq`

### Requirement: Version changes made outside Versioniq are recorded with their trigger

The system MUST keep the last version and enabled state it saw for every app, and MUST update it after every install through Versioniq so that such an install is never recorded twice. For every app, pinned or not, a version change reported by `OCP\App\Events\AppUpdateEvent` MUST be recorded as an `external_update` audit entry with the actor (the session user, or `system`), the previous and the new version, the bound source, and the trigger: the `occ` command and its arguments on the command line, with password and token values redacted, or the request method and path in a web request, or `unknown`. At least every hour the system MUST compare every app with what it last saw, and MUST record a change no event reported as `external_update` with a trigger saying it was found by the hourly check. The first comparison after install MUST set a baseline and record nothing. A pinned app MUST keep its `pin_drift` entry as well.

#### Scenario: An update on Nextcloud's Apps page is recorded

- **GIVEN** `calendar` is not pinned and Versioniq last saw it at 5.1.0
- **WHEN** admin `bob` updates it to 5.2.0 on Nextcloud's own Apps page
- **THEN** the History tab MUST show an `external_update` entry by bob from 5.1.0 to 5.2.0 with the web request path as the trigger

#### Scenario: A command-line update names the command

- **GIVEN** Versioniq last saw `openregister` at 2.3.0
- **WHEN** an operator runs `occ app:update openregister`
- **THEN** the entry MUST record 2.3.0 to the new version, actor `system`, and the trigger `occ app:update openregister`

#### Scenario: Files replaced by hand are found within the hour

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** Versioniq last saw `forms` at 4.3.0, and an operator restored a backup holding 4.2.1 without any update event
- **WHEN** the hourly check runs
- **THEN** an `external_update` entry from 4.3.0 to 4.2.1 MUST say it was found by the hourly check

#### Scenario: Versioniq's own install is not counted twice

- **WHEN** an admin installs `openregister` 2.4.0 through Versioniq
- **THEN** the History tab MUST show the `install` entry only, and no `external_update` entry for it

### Requirement: Every audit row says where it came from

Every audit row MUST record its origin: `web` for the admin page, `cli` for `occ`, `job` for the nightly automatic update, `listener` for drift detection. The History tab MUST show it next to the actor and MUST let the admin filter on it. Rows written before this change MUST show the origin as unknown.

#### Scenario: An admin tells an automatic update from a command-line one

- **GIVEN** the nightly job updated `openregister` and an admin ran `occ versioniq:install calendar 5.1.0`
- **WHEN** the admin opens the History tab
- **THEN** the `openregister` row MUST read "Automatic" and the `calendar` row "Command line"
- **AND** filtering on "Automatic" MUST list only the `openregister` row

### Requirement: Changes to the update rules are audited

Setting or clearing an app's policy, changing the automatic update switch or window, and changing the advisory settings MUST each write an audit row with the actor, the origin and the value before and after. The policy selector MUST show who set the app's policy and when.

#### Scenario: Turning automatic updates on leaves a trace

- **GIVEN** automatic updates are off
- **WHEN** admin `alice` turns them on and sets the window to 02:00-04:00
- **THEN** the History tab MUST show an `auto_update_settings` row by `alice` reading `enabled: false -> true` and the old and new window

### Requirement: Audit rows reach the platform's audit log and activity stream

After recording an audit row, the system MUST dispatch the platform's critical action event with a sanitised message, so the `admin_audit` app writes it to the audit log target the admin configured. The system MUST publish installs, rollbacks, pins, unpins and policy changes to the activity stream of every admin, under a Versioniq activity setting. Neither MAY fail or change the operation it records.

#### Scenario: The security team's log collector sees an update

- **GIVEN** `admin_audit` is enabled with `log_type_audit` set to syslog
- **WHEN** an admin updates `openregister` through Versioniq
- **THEN** a syslog line MUST name Versioniq, the app, both versions, the actor and the origin

#### Scenario: An admin reads the update in their activity stream

- **GIVEN** the activity app is enabled and admin `bob` keeps the Versioniq activity setting on
- **WHEN** admin `alice` pins `openregister` to 2.3.0
- **THEN** `bob`'s activity stream MUST show that `alice` pinned `openregister` to 2.3.0

### Requirement: Advisory exposure and server updates are recorded in the history

After every advisory check the system MUST write an `advisory_open` audit entry for each advisory that newly affects the installed version of an app or of the server, and an `advisory_resolved` entry for each advisory that no longer affects it, stating whether it was resolved by an update, by a dismissal, by the advisory being withdrawn, or otherwise. Each such entry MUST name the advisory and its severity in the `advisories` column, and the versions involved. The system MUST NOT resolve an advisory for an app the check could not reach. When the check sees that the Nextcloud server version changed, it MUST write a `server_update` entry with the previous and the current version. These entries MUST follow the same best-effort, immutable and retention rules as every audit entry. Advisories already affecting the instance when tracking starts MUST be marked as such.

#### Scenario: An update resolves an advisory

@e2e tests/e2e/audit.spec.ts

- **GIVEN** a check found `fixtureapp` 1.0.0 affected by a fixture advisory
- **WHEN** an admin installs 1.0.1, which the advisory does not affect, and the check runs again
- **THEN** the history MUST hold an `advisory_open` entry for 1.0.0 and an `advisory_resolved` entry from 1.0.0 to 1.0.1 stating it was resolved by an update

#### Scenario: An unreached app keeps its advisory open

@e2e exclude covered by AdvisoryExposureTrackerTest.

- **GIVEN** an advisory affects `deck` and the next check cannot reach the source of `deck`
- **WHEN** the check completes
- **THEN** no `advisory_resolved` entry MUST be written for `deck`

#### Scenario: A server upgrade appears in the history

@e2e exclude the CI server cannot be upgraded during the e2e run; covered by AdvisoryExposureTrackerTest.

- **GIVEN** the last check saw server version 32.0.3
- **WHEN** the server runs 32.0.4 at the next check
- **THEN** the history MUST hold a `server_update` entry from 32.0.3 to 32.0.4

### Requirement: An admin reports on the security updates of a period

`GET /api/audit` MUST accept a period (`from`, `to`) and a filter for security updates only. A security update is a successful `install` or `server_update` entry after which an advisory was resolved by that update; each such row MUST name the advisories it fixed. The History tab MUST offer the period, the security filter, and an export of the filtered entries to CSV and to JSON, stating when an export was cut at 5000 rows.

#### Scenario: A monthly overview of security updates

@e2e tests/e2e/audit.spec.ts

- **GIVEN** this month `fixtureapp` was updated from 1.0.0 to 1.0.1, which resolved a fixture advisory, and another app was reinstalled at the same version
- **WHEN** an admin sets the History tab to this month with "Security updates only" and exports CSV
- **THEN** the list and the file MUST contain the `fixtureapp` update with the advisory it fixed
- **AND** they MUST NOT contain the reinstall


## User Stories

1. As a Nextcloud admin, I want to see who rolled an app back, to which version, and when, so that a Friday-evening rollback is visible Monday morning.
2. As a security officer, I want failed install attempts and unsigned-source installs (with their integrity warnings) on record, so that reviews don't depend on rotated server logs.
3. As a Nextcloud admin, I want the trail to be immutable, so that the record is trustworthy even when the actor is also an admin.

## Acceptance Criteria

- [ ] `app_versions_audit` table created by migration; indexed on (`app_id`, `created_at`) and `created_at`
- [ ] Both installers (App Store + external) write entries on success and failure
- [ ] Bind/rebind writes `bind_source` entries (explicit endpoint and implicit install binding)
- [ ] Audit insert failure never fails an install; it is logged
- [ ] `GET /api/audit` is admin-only, paginated, filterable by `appId`; no mutation routes
- [ ] Global History view + per-app History tab render the trail
- [ ] Daily prune job enforces `audit_retention_days` (default 365, floor 30)
- [ ] No secrets ever stored in audit rows
- [ ] `pin`/`unpin`/`pin_drift` operations are recorded through the same write path; `pin_drift` is idempotent per drifted version
- [ ] `composer check:strict` passes; PHPUnit suite passes

## Notes

- Operation vocabulary is an open string set (`[a-z_]{1,32}`); this spec defines `install` and `bind_source`, extended by the "Pin lifecycle operations are audited" requirement above with `pin`, `unpin`, `pin_drift` (no schema change — same table, same `GET /api/audit` read path).
- Downgrade vs upgrade is derived from comparing `from_version`/`to_version`; no separate operation value is needed.
- This app deliberately stores audit state locally (no OpenRegister) — it wraps NC installer internals and keeps its DB surface minimal (`pats` + `audit`).
