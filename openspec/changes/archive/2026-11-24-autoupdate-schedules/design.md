# Design: autoupdate-schedules

Read against `development` at 02e1050 (2026-09-27).

## Context

- `AutoUpdateJob` wakes every 15 minutes (`lib/BackgroundJob/AutoUpdateJob.php:60`). `run()` (`:82`) returns when the kill switch is off (`:83`), reads the time in Nextcloud's `default_timezone` (`:89-90`), returns outside the window (`:91-96`), and sweeps once per opening of the window through an opening key (`:98-103`). It then walks every policy (`:105`) and asks `CandidateSelector::select()` for the highest qualifying version (`:156`).
- `AutoUpdateWindow::isWithin()` (`lib/Service/AutoUpdate/AutoUpdateWindow.php:45`) knows only a time of day. `AutoUpdateWindow::openingKey()` (`:75`) already works out the date the window opened, moving back one day after midnight inside a midnight-crossing window (`:82-84`). That date is the right day to test a weekday against.
- `AutoUpdateSettingsStore` (`lib/Service/AutoUpdate/AutoUpdateSettingsStore.php`) holds `auto_update_enabled` (`:28`) and `auto_update_window` (`:29`) as plain app config values, and the zone through `getTimeZoneName()` (`:49`).
- `CandidateSelector::select()` (`lib/Service/AutoUpdate/CandidateSelector.php:46`) filters by the policy level and returns the highest candidate. It knows nothing about days.
- `ApiController::policies()` (`lib/Controller/ApiController.php:713`) returns the policies with the kill switch, window and zone. `updateAutoUpdateSettings()` (`:822`) validates and stores the switch and window.
- The settings form sits on the Apps tab (`src/App.vue:2080-2128`), with the window as one text field (`:2094-2104`). `AutoUpdateOverview.vue` says "Runs in the window {window} ({timeZone})" (`src/components/AutoUpdateOverview.vue:69-71`).

## Goals and non-goals

Goals: an admin chooses the weekdays the window runs on, per kind of update, weekly or on the first such day of the month, and sees when each kind runs next.

Non-goals: cron expressions, several windows, a schedule per app, holding updates for announcement (see `autoupdate-proposed-updates`), security fixes that skip the schedule (see `autoupdate-security-first`).

## Decisions

### D1. One schedule per kind of update, not per policy level

The three kinds are the size of the step from the installed version to the candidate: `patch` (same `major.minor`), `minor` (same major) and `major` (anything else). A version that does not parse as `major.minor.patch` counts as `major`, the rarest kind, so a strange version string never runs more often than a real major. `UpdateType::classify(string $installed, string $candidate)` implements this with the same pattern `CandidateSelector` uses.

Alternative considered: a schedule per policy level (`patch`, `minor`, `all`). Rejected. The level bounds how far an app may move; the schedule is about when a kind of step may happen. An app on `all` would otherwise get majors and patches on the same days, which is the case the demand row asks to split.

### D2. The stored shape

App config key `auto_update_schedule`, JSON:

```json
{"patch": {"days": [1,2,3,4,5,6,7], "monthly": false},
 "minor": {"days": [2], "monthly": false},
 "major": {"days": [6], "monthly": true}}
```

- `days` holds ISO-8601 weekday numbers, 1 is Monday and 7 is Sunday. An empty list means the kind never installs automatically.
- `monthly: true` limits the kind to the first occurrence of one of its days in a month: a date whose day of the month is 1 to 7.
- A missing key, a missing kind or a value that fails to parse means every day, weekly. That is today's behaviour, so an existing instance changes nothing until an admin saves a schedule. A malformed value is logged, like `PolicyStore` does for a malformed policy.

`AutoUpdateSchedule` is a value object with `fromArray()`, `toArray()`, `isValidArray()` and the two questions below. `AutoUpdateSettingsStore` gains `getSchedule()` and `setSchedule()`.

Alternative considered: a `days` list on the global window plus a separate per-kind override. Rejected: two layers that must agree. One per-kind list, with a "same days for every kind" mode in the form, covers both rows with one rule.

### D3. The day that counts is the day the window opened

`AutoUpdateSchedule::dueKinds(\DateTimeInterface $openedOn): list<string>` answers which kinds may run on that date. The job takes the date from the opening key's date part (`AutoUpdateWindow::openingKey()`), so the 00:30 wake-up inside a 23:00-03:00 window that opened on Friday is a Friday run. `AutoUpdateWindow` gains a small `openedOn()` helper that returns that date, so the key and the check cannot drift apart.

### D4. The job filters candidates, it does not skip apps

When `dueKinds()` is empty the job marks the opening as swept and returns, with no source query. Otherwise it passes the due kinds to `CandidateSelector::select()` as a new optional fourth argument, defaulting to all three kinds so every current caller and test keeps working. `select()` keeps only candidates whose `UpdateType` is due and returns the highest of those.

So an app on `minor` with 2.3.4 (patch) and 2.4.0 (minor) available installs 2.3.4 on a patch-only night and 2.4.0 on the minor night. A never-retry entry in `AttemptLedger` still applies to whatever version is chosen.

Alternative considered: skip an app whose highest candidate is not due. Rejected: the patch that is due would then wait for the minor's day, which defeats a daily patch schedule.

### D5. Next run per kind

`AutoUpdateSchedule::nextRun(string $kind, string $window, \DateTimeImmutable $now): ?\DateTimeImmutable` walks forward at most 62 days from today and returns the first opening time whose date is due for that kind, or null when the kind has no days. `GET /api/policies` returns `autoUpdateSchedule` and `nextRuns: {patch, minor, major}` as ISO-8601 strings in the configured zone, or null. The overview reads them; the page never computes dates itself, so the browser's zone cannot disagree with the server's.

### D6. API and form

`PUT /api/auto-update/settings` accepts an optional `schedule` object. It is validated in full before anything is written: three known kinds at most, `days` a list of distinct integers from 1 to 7, `monthly` a boolean. A bad schedule is refused with HTTP 400 and a message naming the field, and neither the switch nor the window is written. The route keeps `PasswordConfirmationRequired` and the `isAdmin()` guard.

`src/components/AutoUpdateSchedule.vue` renders under the window field. A choice between "Same days for every update" and "Different days per kind of update". Weekday checkboxes use the localized short day names from `@nextcloud/l10n` and start on the user's first day of the week. Each kind gets a checkbox "Only the first of these days each month". The component emits the schedule; `App.vue` sends it with the switch and window in one save.

The overview line becomes, per kind with a policy that can reach it: "Patch updates: Mon to Fri, next run Tue 1 Oct 01:00 (Europe/Amsterdam)". A kind with no days reads "Major updates: never automatically".

## Risks and trade-offs

- [An admin picks no days for every kind and thinks automation is on] → the overview says "No kind of update is scheduled, so nothing runs" next to the kill switch, like the existing "automation is off" hint.
- [A monthly major on the first Saturday is missed because cron did not run that night] → the next chance is a month later. The overview shows the next run, and `autoupdate-proposed-updates` lists what is waiting.
- [Time zone changes move a run by a day] → the day is always read in the zone the window uses, and the overview shows that zone next to each time.

## Migration

No schema change. The new key is absent on every existing instance, which reads as every day for every kind. Rollback: revert; the key is inert.
