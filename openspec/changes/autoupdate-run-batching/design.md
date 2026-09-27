# Design: autoupdate-run-batching

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AutoUpdateJob::run()` (`lib/BackgroundJob/AutoUpdateJob.php:82`) walks every stored policy (`:105`) and calls `processApp()` for each in its own try/catch (`:110-119`). `processApp()` (`:123`) skips pinned apps before any source query (`:124-128`), reads the installed version (`:134-143`), lists versions (`:145-148`), picks a candidate (`:156`), honours the never-retry ledger (`:161-164`) and installs through `attemptInstall()` (`:169`). Selection and installation are interleaved, so nothing can look at the whole run before it starts.
- `AttemptLedger` (`lib/Service/AutoUpdate/AttemptLedger.php`) stores up to ten attempts per app under `auto_attempt.{appId}` with an ISO `at` and an outcome (`:61-75`). `blockedVersions()` (`:84`) lists failed ones. Those timestamps are enough to count attempts in the last seven days.
- `AutoUpdateNotifier` (`lib/Service/AutoUpdate/AutoUpdateNotifier.php`) sends one `auto_update_success` (`:48`) or `auto_update_failure` (`:63`) per app to every admin. `Notifier::prepare()` renders those subjects (`lib/Notification/Notifier.php:136`, `:155`).
- `GET /api/policies` (`lib/Controller/ApiController.php:713`) and `PUT /api/auto-update/settings` (`:822`) carry the global auto-update settings. `AutoUpdateOverview.vue` lists scheduled and blocked updates (`src/components/AutoUpdateOverview.vue:32-39`).

## Goals and non-goals

Goals: related apps move together or wait together; a run and a week have an upper bound; what the bound held back is visible and goes first next time; the run is planned before it executes.

Non-goals: rollback of a partly installed group (see `install-bulk-rollback`), a preview endpoint (see `autoupdate-proposed-updates`), exempting security fixes from the cap (see `autoupdate-security-first`).

## Decisions

### D1. Plan first, then execute

`AutoUpdatePlanner::plan(\DateTimeImmutable $now): AutoUpdatePlan` does everything `processApp()` does up to the candidate, for every policy, and returns units. A unit is one app or one group, each member carrying `appId`, `installedVersion`, `candidate` or null, and a `reason` when it will not install (`pinned`, `no_candidate`, `source_error`, `blocked`, `limit`). `AutoUpdateJob::run()` calls the planner and then installs unit by unit through the existing `attemptInstall()`. The planner makes the same source calls the job made before, and no others.

Alternative considered: keep the per-app loop and bolt counters onto it. Rejected: a group needs to know all its members' candidates before installing the first one, and a cap needs the whole list to decide what waits.

### D2. Groups

App config key `auto_update.groups`, JSON list of `{id, name, apps, setBy, setAt}`. `apps` is ordered, 2 to 20 app ids, and an app belongs to at most one group. `UpdateGroupStore` reads and writes it with the malformed-value handling `PolicyStore` uses. `PUT /api/auto-update/groups` replaces the whole list, admin-only and password-confirmed, and refuses with 400 a duplicate name, an app in two groups, a group of one, or an app `isManageableApp()` rejects. `GET /api/policies` returns the groups.

A group only plans members that have a policy other than `none`. Members without a policy are listed on the group as "no policy" and never install.

### D3. A group moves together or waits

In the plan, a group installs only when all of these hold for every member with a policy: the source answered, the member is not pinned, and the member's candidate is not a failed version in `AttemptLedger`. Otherwise every member gets the reason of the first member that held it (`source_error`, `pinned` or `blocked`), and nothing in the group installs. Members with no candidate are fine; they are already current.

During execution the job installs members in the stored order. At the first failure it stops. Members after it are reported "not attempted" and are not written to the ledger, so the next run plans them again. The failed member's version is in the ledger, so the next run holds the whole group as `blocked` until an admin retries it. That keeps a group from drifting apart one member at a time.

Alternative considered: install the members that can install and skip the rest. Rejected: that is exactly the half-moved state a group exists to prevent.

Every install audit entry from a group carries the message "Update group {name}". One `auto_update_group` notification per group run lists each member with its outcome; the per-app success and failure notifications are not sent for group members, so an admin gets one message per group, not one per app.

### D4. Two caps

`auto_update.max_per_run` (1 to 50) and `auto_update.max_per_week` (1 to 200). Empty means no cap, which is the default and today's behaviour. They are set through `PUT /api/auto-update/settings` and returned by `GET /api/policies`.

The weekly count is the number of ledger attempts, success or failure, with `at` in the last seven days, across all apps. `AttemptLedger` gains `countSince(string $isoTime): int`, which reads every `auto_attempt.*` key through `IAppConfig::getAllValues()` like `PolicyStore::all()` does (`lib/Service/Policy/PolicyStore.php:68`). A run may install at most `min(max_per_run, max_per_week - countSince(now - 7 days))`.

A single app costs one. A group costs the number of members with a candidate, and a group that does not fit in what is left waits whole with reason `limit`.

Alternative considered: count only successful installs. Rejected: a failing update still took the instance through maintenance mode; the cap is about how much happens in a night, not how much went well.

### D5. Who goes first

Units run in this order: first those the cap held back last time, oldest first, then the rest by app id or group name. The held-back list lives in `auto_update.deferred` as `{unitKey: firstHeldAt}`. A unit leaves it when it installs or no longer has a candidate. The overview lists each held unit as "Waiting for the limit since {date}".

### D6. The page

`src/components/UpdateGroupsPanel.vue` renders inside the Automatic updates block: the groups with their ordered apps, a form to add or change one (a name field and an `NcSelect` with `inputLabel`, plus up and down buttons for order), and a Save that sends the whole list. The limits are two number fields next to the window. `AutoUpdateOverview.vue` gains a groups list and the waiting list.

## Risks and trade-offs

- [A permanently failing member blocks its group forever] → the overview shows the group as blocked by that member with its Retry action, and the admin can take the app out of the group.
- [A low weekly cap starves apps that sort late] → the waiting list goes first, so every held unit reaches the front within the number of runs its position needs.
- [Planning costs a source call per app before anything installs] → the job made the same calls before; they only happen earlier in the run. The install phase still takes as long as before.
- [Counting reads every ledger key] → at most ten entries per app, read once per run.

## Migration

No schema change. All new keys are absent on existing instances, which reads as no groups and no caps, so the job behaves as before until an admin sets them. Rollback: revert; the keys are inert.
