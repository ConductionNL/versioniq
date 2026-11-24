# Design: autoupdate-security-first

Read against `development` at 02e1050 (2026-09-27).

## Context

- `Policy` (`lib/Service/Policy/Policy.php`) knows four levels (`:28-34`) and validates them in `isValidLevel()` (`:58`). `ApiController::setPolicy()` (`lib/Controller/ApiController.php:752`) refuses any other level with 400 (`:763-768`). `PolicyStore` treats an invalid stored level as no policy and logs it (`lib/Service/Policy/PolicyStore.php:128-137`).
- `CandidateSelector::select()` (`lib/Service/AutoUpdate/CandidateSelector.php:46`) returns the highest version within the level. It never sees advisories.
- `AutoUpdateJob::run()` walks policies in stored order (`lib/BackgroundJob/AutoUpdateJob.php:105`); nothing orders one app before another.
- The advisory sweep stores, per app, `state`, `advisories` (id, severity, summary) and `recommendedVersion` (`lib/Service/Advisory/AdvisoryService.php:308-315`), keyed by app id, with the time of the sweep (`lib/Service/Advisory/AdvisoryResultStore.php:60`, `:86`). The recommended version is the branch patch from the advisory when there is one, else the nearest newer version that clears every active advisory (`AdvisoryService.php:304-306`).
- The sweep runs every `AdvisorySettingsStore::getIntervalHours()` hours (`lib/Service/Advisory/AdvisorySettingsStore.php:61`), 6 by default.
- `AdvisoryNotifier` has no dependency on the installer, which is how the current spec proves an advisory can never change a version (`lib/Service/Advisory/AdvisoryNotifier.php:28-31`).
- `SettingsController` writes a `settings` audit entry for Settings tab changes (`lib/Controller/SettingsController.php:120-134`); `AuditLogger` accepts any operation matching `[a-z_]{1,32}` (`lib/Service/Audit/AuditLogger.php:41`, `:69`).
- `autoupdate-default-and-config-file` also modifies the requirement `Per-app update policy [MVP]` (the default level). Whichever of the two changes archives second must carry both edits into the requirement text.

## Goals and non-goals

Goals: a level that installs only advisory fixes; a written patch policy with deadlines per severity and handling per kind; security fixes that go first; the advisory requirement stating plainly that only an advance policy may change a version.

Non-goals: reacting to an advisory outside the window, unpinning, a default level for every app (see `autoupdate-default-and-config-file`), deadline reports (see `audit-security-reports`).

## Decisions

### D1. The `security` level and its candidate

`Policy::LEVEL_SECURITY = 'security'` joins `VALID_LEVELS`. For an app on it, `SecurityCandidate::for(string $appId, string $installed, array $snapshotRow, array $available): ?string` returns the row's `recommendedVersion` when all of these hold:

- the stored row's `state` is `pinned-to-vulnerable`, which is the only state that means the installed version is affected;
- `recommendedVersion` is newer than the installed version and has the same major;
- the bound source lists it (it is in `$available`).

Otherwise it returns null with a reason: `not_affected`, `no_fix`, `needs_major`, `not_in_source` or `stale`. The overview shows the reason for every `security` app that has an affected version, so "no install" is never silent.

The job still skips pinned apps before any source query, as it does now (`AutoUpdateJob.php:124-128`). The advisory refresh job stays read-only; the install happens only in `AutoUpdateJob`, in the window.

Alternative considered: install the highest version in the same major that is not affected. Rejected for now. The recommended version is what the advisory publisher names as the fix, and the smallest step is the one least likely to break. An admin who wants more puts the app on `minor`.

### D2. The snapshot must be fresh

The job reads `AdvisoryResultStore::read()` once per run. When `checkedAt` is null or older than twice the advisory interval, every security decision in that run returns `stale` and nothing installs for a security reason. The overview says "Advisories were not checked recently, so security fixes wait". An old snapshot could name a fix for an advisory that was since withdrawn, or miss that the app was already updated by hand.

### D3. The patch policy

App config key `patch_policy`, JSON:

```json
{"security": {"critical": {"deadlineDays": 3, "handling": "next_window"},
              "high": {"deadlineDays": 7, "handling": "next_window"},
              "medium": {"deadlineDays": 30, "handling": "scheduled"},
              "low": {"deadlineDays": 90, "handling": "scheduled"},
              "unknown": {"deadlineDays": null, "handling": "manual"}},
 "regular": {"patch": "automatic", "minor": "automatic", "major": "manual"},
 "statement": "Free text, at most 4000 characters.",
 "setBy": "alice", "setAt": "2026-09-27T10:00:00+00:00"}
```

The values above are an example an admin might save, not defaults. With no stored policy, every severity reads as `scheduled` with no deadline and every regular kind as `automatic`, which is exactly today's behaviour, and the page says no policy is recorded yet.

- `deadlineDays`: 1 to 365, or null for none. Stored for reports; the job does not read it.
- Security `handling`: `next_window` (the fix installs in the next window, before other updates, not held by a kind's days or a run limit), `scheduled` (the fix installs like any other update of its kind), `manual` (never installed automatically; the admin is notified as today).
- Regular `handling`: `automatic` (the app's level decides) or `manual` (the job never installs that kind for any app). A security fix is not a regular update, so a `manual` major does not block a security fix that the security handling allows.

`PatchPolicyStore` reads and writes it; a malformed value is logged and read as no policy. `GET /api/patch-policy` returns the record, the rendered statement lines (D5) and the allowed values. `PUT /api/patch-policy` is admin-only and password-confirmed, validates everything before writing, and records `setBy` and `setAt`.

Alternative considered: keep the patch policy as free text only. Rejected: the tender asks how each type of patch is handled, and a statement nothing enforces drifts from what the job does. Each sentence the page renders is generated from a stored value the job reads.

### D4. Security first in the run

The job orders the run in two passes. First every app whose stored row is affected and whose policy is not `none`: `security` apps through D1, and `patch`, `minor` and `all` apps when their level's selection includes the recommended version. The severity that decides handling is the highest among the app's active advisories. With `next_window` the fix installs in this pass whatever the kind's days and whatever run limit is set (where `autoupdate-schedules` and `autoupdate-run-batching` exist, they skip these installs in their checks). With `scheduled` the app waits for the second pass. With `manual` it is left alone. Then the second pass handles every remaining app as today, and skips kinds the patch policy marks `manual`.

A dismissed advisory (`advisories-triage`) does not count as active here.

### D5. The statement and its audit trail

The server renders the policy as one sentence per row with `IL10N`, for example "Critical security fixes: installed at the next update window. Deadline: 3 days." and "Major updates: installed by hand only.", followed by the admin's free text and "Last changed by alice on 27 September 2026". `PatchPolicyPanel.vue` on the Settings tab shows the form and this statement, with a "Download as text" button that saves those lines as a `.txt` file. The same lines the page shows are what the file holds.

Every `PUT /api/patch-policy` that changes something writes an audit entry with operation `patch_policy`, `app_id` `versioniq`, and a message listing the changed fields, like the `settings` entry does.

### D6. The advisory requirement

The MODIFIED requirement keeps the notification half as it is. It replaces "MUST NOT auto-update or auto-unpin" with: no version changes in response to an advisory unless a policy the admin set beforehand allows it, and then only through the nightly job, in the window, through the standard installer; never an unpin. The structural guarantee stays: `AdvisoryNotifier` and `AdvisoryRefreshJob` still have no installer dependency, and a unit test asserts that.

## Risks and trade-offs

- [A security fix installs a version that breaks the app] → it goes through the standard installer with backup and restore, the outcome is notified and audited, and the admin chose the level. The failed version is blocked by the attempt ledger like any other.
- [Admins read `security` as "keeps me safe" while the fix needs a major] → the overview names the reason `needs_major` for that app, and the advisory tab already shows the recommended version.
- [A `manual` regular major silently stops `all` apps from moving] → the overview shows "Major updates are set to by hand in the patch policy" on every app it holds.

## Migration

No schema change. The new level and key are absent everywhere, and an absent patch policy reproduces today's behaviour. Rollback: see the proposal; a stored `security` level reads as `none` after a revert.
