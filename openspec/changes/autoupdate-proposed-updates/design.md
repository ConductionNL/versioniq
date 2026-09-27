# Design: autoupdate-proposed-updates

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AutoUpdateJob::processApp()` (`lib/BackgroundJob/AutoUpdateJob.php:123`) lists versions (`:145`), selects a candidate (`:156`), checks the never-retry ledger (`:161-164`) and installs at once (`:166`). The candidate is never stored or shown.
- `InstallerService::getAppVersions()` (`lib/Service/InstallerService.php:185`) returns each version with its `changelog` (truncated to 8 KiB by `applyChangelogTruncation()`, `:260`) and a nullable `serverCompatible`. So the release notes of a candidate are already in hand whenever its version list is.
- The manual Dry run toggle on the Apps tab (`src/App.vue:2063-2070`) passes `dryRun` to one install through `InstallerService::installAppVersion()` (`lib/Service/InstallerService.php:407`). It does not evaluate the policy.
- `AutoUpdateNotifier` sends to every member of the `admin` group (`lib/Service/AutoUpdate/AutoUpdateNotifier.php:107-114`) with a link to the Versioniq settings section (`:77-80`). `Notifier::prepare()` renders each subject (`lib/Notification/Notifier.php:57`).
- `Policy` stores `level`, `setBy` and `setAt`; `fromArray()` reads only those three keys (`lib/Service/Policy/Policy.php:82-91`), so an extra key in stored JSON is ignored by older code.
- Sibling changes this one reads: `inventory-pending-updates` adds `AvailabilityService::sweep()`, which lists every installed app's versions every 6 hours; `autoupdate-run-batching` adds `AutoUpdatePlanner::plan()`; `autoupdate-schedules` adds `AutoUpdateSchedule::nextRun()` and `UpdateType`.

## Goals and non-goals

Goals: one stored proposal per app, computed ahead of the window with no extra source call; a preview on the page and a live dry run on the CLI; approval and decline per exact version; a notice period with release notes to admins and a chosen group.

Non-goals: installing from the list by hand (see `install-one-click-updates`), breaking-change flags (see `releases-version-facts`), non-admin access to Versioniq.

## Decisions

### D1. Proposals come from the availability sweep

After `AvailabilityService::sweep()` has listed versions for every app, `ProposalService::refresh(array $versionLists, \DateTimeImmutable $now)` asks `AutoUpdatePlanner` for the plan of the next due opening of the window, using those lists instead of new source calls. For every app with a policy it stores one `ProposedUpdate`:

| Field | Meaning |
|---|---|
| `appId`, `fromVersion`, `toVersion`, `kind` | the step, `kind` from `UpdateType` or `security` |
| `plannedFor` | ISO time of the opening it is planned for, from `AutoUpdateSchedule::nextRun()`, after the notice period |
| `proposedAt`, `announcedAt` | first time this exact version was proposed; when the announcement went out |
| `releaseNotes` | the target version's changelog, cut to 2000 characters |
| `status` | `planned`, `in_notice`, `awaiting_approval`, `approved`, `declined` |
| `approvedBy`, `approvedAt`, `declinedBy`, `declinedAt`, `declineReason` | the admin's decision on this version |
| `reason` | for apps without a proposal: the planner's reason (`pinned`, `blocked`, `not_due`, `limit`, `no_candidate`, `source_error`) |

`ProposalStore` keeps them under app config key `auto_update.proposals` with `computedAt`, on the `AdvisoryResultStore` pattern: a snapshot that fails to encode keeps the previous one.

Alternative considered: a separate job that lists versions for proposals. Rejected: it doubles the source calls, and GitHub allows 60 anonymous calls an hour.

### D2. A proposal keeps its version once announced

When a newer version appears for an app whose proposal is already announced or approved, the stored proposal keeps its `toVersion`. The newer version becomes the next proposal once the current one is installed or no longer valid (no longer listed, or declined). Otherwise an app that releases more often than the notice period would never install anything. A proposal that was only `planned` (no notice, no approval yet) simply moves to the newer version.

### D3. The preview and the dry run

`GET /api/auto-update/proposals` (admin-only) returns the stored proposals, the reasons for apps without one, and `computedAt`. It never calls a source, for the reason issue #160 gave: a live answer across every app does not fit in a page request. `src/components/ProposedUpdatesPanel.vue` on the Apps tab lists them in planned order, with the release notes behind a toggle and the time they were computed.

`occ versioniq:auto-update --dry-run` runs the planner live against the sources for the next due opening, applies notice, approval and declines, and prints the plan as a table, or as JSON with `--json`. It installs nothing, writes no ledger entry and sends no notification. Exit code 0 when it printed a plan, 1 when automatic updates are off (it still prints the plan, headed "automatic updates are off"). The command has no mode that installs; the nightly job remains the only automatic installer.

Alternative considered: a live preview button on the page. Rejected for the timeout reason above; the page shows when the stored preview was computed and the CLI gives a live answer.

### D4. Approval

`Policy` gains `requireApproval` (bool, default false), written by `PUT /api/app/{appId}/policy` and returned by `GET /api/policies`. `PolicySelector.vue` gets a checkbox "Ask me before installing".

For an app with `requireApproval`, the job installs only when the stored proposal for that app has `status` `approved` and `toVersion` equal to the version the job would install. `POST /api/auto-update/proposals/{appId}/approve` with `{version}` approves exactly that version. Approving 2.3.4 never approves 2.4.0.

`POST /api/auto-update/proposals/{appId}/decline` with `{version, reason}` declines a version for any app, with or without `requireApproval`. The job and the planner skip a declined version until `DELETE /api/auto-update/proposals/{appId}/decline/{version}` withdraws it. All three routes are admin-only and password-confirmed, and write audit entries `proposal_approve`, `proposal_decline` and `proposal_withdraw` with the version in `to_version`.

### D5. Notice period

`auto_update.notice_days` (0 to 60, 0 or empty is off) and `auto_update.notice_group` (a Nextcloud group id, optional) are set with the other automatic update settings. When notice is on:

- The proposal step sends an `auto_update_planned` notification the first time it stores a proposal for a new version, and sets `announcedAt`. It goes to every admin and to every member of the notice group. The message carries the app, both versions, the planned date and the release notes excerpt, and links to the Versioniq settings section.
- `plannedFor` is the first due opening at or after `announcedAt` plus `notice_days`.
- The job installs a proposal only when the current opening is at or after `plannedFor`.

Members of the notice group get a notification and nothing else: they gain no access to Versioniq, whose endpoints stay admin-only. The notification text therefore carries the release notes itself, not only a link.

A security fix with patch policy handling `next_window` (see `autoupdate-security-first`) is announced the same way but is planned for the next opening, without the notice period, and its notification says so. Approval still applies to it when the app requires approval.

### D6. What the job does now

For each unit the planner hands it, the job checks, in order: declined version (skip), notice (skip until `plannedFor`), approval (skip unless approved for that version). Skipped units keep their proposal. Without notice, without approval and without declines, nothing changes for the job.

## Risks and trade-offs

- [The stored proposal is up to 6 hours old] → the page shows `computedAt`, the job re-plans live at run time and only installs a version that still qualifies, and `occ versioniq:auto-update --dry-run` answers live.
- [An approved version is withdrawn upstream before the window] → the live plan no longer lists it, so the job installs nothing and the next proposal step replaces it.
- [Notifications to a large group] → one notification per new proposal per member, never repeated for the same version; the group is optional.
- [Approval nobody gives blocks updates forever] → the proposal shows "Awaiting approval since {date}", and a security fix awaiting approval is listed first.

## Migration

No schema change. New app config keys are absent on existing instances, which reads as no notice and no approval, so the job behaves as before. `requireApproval` is absent from stored policies and reads as false. Rollback: revert; the keys are inert and older code ignores the extra policy field.
