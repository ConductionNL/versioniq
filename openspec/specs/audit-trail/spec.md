---
status: implemented
---

# Audit Trail Specification

**Status**: implemented
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

## User Stories

1. As a Nextcloud admin, I want to see who rolled an app back, to which version, and when, so that a Friday-evening rollback is visible Monday morning.
2. As a security officer, I want failed install attempts and unsigned-source installs (with their integrity warnings) on record, so that reviews don't depend on rotated server logs.
3. As a Nextcloud admin, I want the trail to be immutable, so that the record is trustworthy even when the actor is also an admin.

## Acceptance Criteria

- [ ] `versioniq_audit` table created by migration; indexed on (`app_id`, `created_at`) and `created_at`
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
