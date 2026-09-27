---
kind: code
---

# Proposal: autoupdate-schedules

## Why

An admin can pick one time window for automatic updates, and that window opens every night. There is no way to say "only on weekdays", or "patches any night, but a new major version only on the first Saturday of the month". Admins who want to keep weekend or month-end nights quiet have to switch automation off and on by hand.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers two rows that share one setting and one check in the nightly job.

| Row | Rating now | What is missing |
|---|---|---|
| `aut-schedule-days` | no | The window opens every day. Nobody can pick the weekdays it runs on. |
| `aut-schedule-per-type` | no | One window applies to every kind of update. A patch and a new major version run on the same nights. |

The archived change `add-auto-update-policies` listed "No per-app windows or cron expressions; one global window" as a non-goal. The lane decided that line bounded that first change, not the product: its design gives the reason as "over-configuration for MVP", and the auto-update feature has since grown an overview, a retry path and a time zone. This change therefore MODIFIES the window requirement in `openspec/specs/auto-update-policies/spec.md`. It keeps the other half of that line: there are still no cron expressions and still one time window. What changes is which days that window runs on, per kind of update.

### Demand

- `aut-schedule-per-type`: feature request, https://github.com/dependabot/dependabot-core/issues/1778. A schedule per update type, for example patches daily and majors monthly.
- `aut-schedule-days`: no demand row in the matrix.

### Competitors rated yes (evidence quoted from the matrix)

- `aut-schedule-days`, three rated yes. Renovate: "schedule (lib/config/options/index.ts:1027) accepts cron syntax and later.js text such as "before 5am on monday"; lib/config/presets/internal/schedule.preset.ts ships weekly and monthly presets." Dependabot: "Service config: schedule.interval daily to yearly, schedule.day, or cron (https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)." Easy Updates Manager: "Premium, docs: every 12 hours, daily, weekly, fortnightly or monthly at a custom day and time (https://easyupdatesmanager.com/knowledge-base/automatic-update-scheduling-premium/); allowed weekdays selectable since 9.0.16 (readme.txt changelog)."
- `aut-schedule-per-type`, Renovate rated yes: "packageRules with matchUpdateTypes (lib/config/options/index.ts:1798) or the major, minor and patch config objects (lib/config/options/index.ts:1999-2017) each take their own schedule (lib/config/options/index.ts:1027), for example patches any time and majors monthly." No other competitor is rated yes.

## What changes

- An admin picks the weekdays the update window runs on. The default is every day, so an instance that never sets it behaves as today.
- An admin can give each kind of update its own days: patch updates, minor updates and major updates. Each kind can also run only on the first of its days in a month, so "majors monthly" is one checkbox.
- A day counts by the date the window opened, in Nextcloud's time zone. A window from 23:00 to 03:00 that opens on Friday is a Friday run, also after midnight.
- On a night where only some kinds are due, the job installs the highest version of a due kind. A patch can go in on Tuesday while the minor waits for Saturday.
- The Automatic updates overview says, per kind, which days it runs and when it runs next.

## Scope

In scope: the schedule setting, its validation, the check in `AutoUpdateJob`, the candidate filter, the next-run calculation, the settings form, the overview line, tests.

Out of scope:
- Cron expressions and more than one time window. The window stays one `HH:MM-HH:MM` range.
- A schedule per app. The schedule is per kind of update, for every app with a policy.
- A default policy for apps without one. `autoupdate-default-and-config-file` specifies that.
- Holding back or batching runs. `autoupdate-run-batching` specifies groups and a limit per run.
- Announcing an update days ahead. `autoupdate-proposed-updates` specifies that and reads the next-run time this change adds.
- Security fixes that should not wait for a type's day. `autoupdate-security-first` specifies that.

## Impact

- New: `lib/Service/AutoUpdate/AutoUpdateSchedule.php` (value object: parse, validate, due kinds, next run), `lib/Service/AutoUpdate/UpdateType.php` (classify a version step as patch, minor or major), `src/components/AutoUpdateSchedule.vue`.
- Changed: `lib/Service/AutoUpdate/AutoUpdateSettingsStore.php` (one key), `lib/Service/AutoUpdate/CandidateSelector.php` (optional due-kinds filter), `lib/BackgroundJob/AutoUpdateJob.php` (due kinds per opening), `lib/Controller/ApiController.php` (`GET /api/policies`, `PUT /api/auto-update/settings`), `src/App.vue` (settings block), `src/components/AutoUpdateOverview.vue`, `l10n/en.json`, `l10n/nl.json`.
- Capability spec `auto-update-policies`: MODIFIED "Global kill switch and window [MVP]", ADDED two requirements.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one, and the schedule is part of the policy data it can read.

## Rollback

Revert the change. The schedule lives in one app config key, `auto_update_schedule`. Without the code nothing reads it, and the job runs every night as before. The key can stay or be deleted with `occ config:app:delete versioniq auto_update_schedule`.
