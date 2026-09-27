# Design: releases-minimum-age

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AutoUpdateJob::run()` (`lib/BackgroundJob/AutoUpdateJob.php:82-121`) sweeps once per window opening, skips pinned apps before any source call (`lib/BackgroundJob/AutoUpdateJob.php:124-128`), lists versions through `InstallerService::getAppVersions()`, maps them to plain strings (`lib/BackgroundJob/AutoUpdateJob.php:150-154`), asks `CandidateSelector::select()` (`lib/Service/AutoUpdate/CandidateSelector.php:46-64`) for the highest newer version inside the level, skips a version the `AttemptLedger` has seen (`lib/BackgroundJob/AutoUpdateJob.php:161-164`), and installs it.
- The job runs only when the kill switch is on and inside the window, and only once per opening (`lib/BackgroundJob/AutoUpdateJob.php:83-103`). So it cannot answer "what would happen next" at any other time.
- A policy is JSON under `policy.{appId}` with `level`, `setBy` and `setAt` (`lib/Service/Policy/Policy.php:36-40`). `Policy::fromArray()` reads those three keys and ignores any other (`lib/Service/Policy/Policy.php:82-90`), so an optional field is safe for old code.
- The advisory sweep stores, per app, `state` and `recommendedVersion` (`lib/Service/Advisory/AdvisoryResultStore.php:58-76`). The state `pinned-to-vulnerable` means the installed version lies inside an advisory's affected range, whether pinned or not (`lib/Service/Advisory/AdvisoryService.php:32-36`), and `recommendedVersion` is the lowest version the advisory or the version list says resolves it (`lib/Service/Advisory/AdvisoryService.php:304-314`).
- The Automatic updates overview lists apps with a policy and blocked versions from `GET /api/policies` (`lib/Controller/ApiController.php:712-737`, `src/components/AutoUpdateOverview.vue:33-40`).
- `releases-version-facts` adds `releasedAt` to every version entry. This change reads it.

## Goals and non-goals

Goals: a version waits a set number of days before the nightly job may install it; an admin sees what waits and until when; one version can be let through early; a security fix never waits.

Non-goals: holding a manual install; per update type ages; approval before a run (`autoupdate-proposed-updates`).

## Decisions

### D1. The setting

`auto_update_min_age_days` in `AutoUpdateSettingsStore`, an integer from 0 to 90, 0 meaning off and the default. A policy may carry `minAgeDays` (0 to 90, or absent to use the instance value). `PUT /api/auto-update/settings` and `PUT /api/app/{appId}/policy` accept the new field, keep their password confirmation, and record the change like today.

### D2. A version's age

`MinimumAgeRule::releasedAt(entry)` returns `releasedAt` when the source gave one. Otherwise it returns the first-seen time from `FirstSeenStore`: JSON under `first_seen.{appId}`, version to ISO 8601 UTC, written the first time the planner sees a version without a date, and pruned to the versions the source still lists. `eligibleAt = releasedAt + minAgeDays`. A version is held when `eligibleAt` is later than now.

Alternative considered: treat a version without a date as eligible. Rejected. The hold exists for new releases, and a forge draft published without a date is exactly that.

### D3. One planner, two callers

`AutoUpdatePlanner::plan(appId, installedVersion, entries, policy, now)` returns:

- `next`: the version a run would install, or null;
- `heldBack`: every version that would qualify on level and channel but is younger than the minimum age, each with `releasedAt`, `eligibleAt` and `reason` (`minimum_age`);
- `securityBypass`: the version let through by D6, or null;
- `exception`: the version let through by D5, or null.

It filters the entries to those `CandidateSelector` would accept, splits them into eligible and held, and hands only the eligible ones to `select()`. So `next` is the newest eligible in-policy version, even when a newer one waits.

`AutoUpdateJob::processApp()` calls the planner on a fresh version list and installs `next`, as today. The job never reads the stored plan, so it never acts on a six-hour-old answer.

Alternative considered: install nothing until the newest version is old enough. Rejected. An app that releases every week would then never update, which is worse than no hold.

### D4. The plan snapshot and where it shows

`AutoUpdatePlanJob` (`TimedJob`, every 6 hours, a 600 s budget, registered in `appinfo/info.xml`) runs the planner for every app with a policy other than `none`, whether the kill switch is on or not, and stores the result with `AutoUpdatePlanStore` under `autoupdate.plan`, with `checkedAt`, keeping the previous snapshot when a new one cannot be encoded (the `AdvisoryResultStore` pattern). Pinned apps are listed as pinned, without a source call.

`GET /api/policies` adds, per app, its `plan` entry and the snapshot's `checkedAt`. `AutoUpdateOverview.vue` adds two lines per app: "Next update: {version}" or "No update qualifies", and for each held version "{version} held back until {date} (minimum age {n} days)". The overview says when the plan was worked out, and says plainly when it never was.

Alternative considered: work the plan out when the page loads. Rejected. It costs a source call per app with a policy, the cost that made `GET /api/advisories` time out in issue #160.

### D5. Releasing one version early

`POST /api/app/{appId}/min-age-exception/{version}` (admin-only, password-confirmed) stores `min_age_exception.{appId}` as `{version, by, at}`; one exception per app, a new one replaces the old. The planner treats that version as eligible. `DELETE` on the same route removes it.

The planner removes the exception, and records an audit row `settings` with the reason, when any of these holds:

- the installed version is that version or newer;
- the version has reached the minimum age anyway;
- the source no longer lists it.

So an exception never outlives its purpose, which is the complaint in Renovate discussion 44513. Adding and removing by hand are audited too.

### D6. A security fix skips the hold

When the advisory snapshot for the app has state `pinned-to-vulnerable` and a `recommendedVersion`, the planner treats that one version as eligible. Newer versions on the same line still wait: they may carry unrelated changes. The level and the channel still apply, so a fix outside a `patch` policy is not installed automatically, and the plan says why.

When the job installs a bypassed version, the audit message and the success notification add "security fix, installed before the minimum age: {advisory ids}".

Alternative considered: bypass the hold for every version at or above the recommended one. Rejected. That turns one advisory into a reason to take the newest release unseasoned, which is what the hold is for.

## Risks and trade-offs

- [The plan is up to six hours old] → the overview shows `checkedAt`, and the job always plans afresh before it installs.
- [The advisory snapshot is up to six hours old, so a bypass can come one sweep late] → the advisory job runs every six hours; a manual install is always possible.
- [First-seen times reset when app config is cleared] → the versions then wait again from that moment, which errs toward waiting.
- [A policy's `minAgeDays` read by an older Versioniq after a downgrade] → `Policy::fromArray()` ignores it, so the old job installs without a hold, as it did before.

## Migration

No schema change. The default of 0 keeps today's behaviour until an admin sets an age. The plan job is registered in `info.xml`, so Nextcloud adds it on upgrade.
