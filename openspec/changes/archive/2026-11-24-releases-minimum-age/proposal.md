---
kind: code
---

# Proposal: releases-minimum-age

## Why

A release that turns out broken is usually pulled or fixed within days. The nightly job installs the newest in-policy version the night it appears, so an instance with automatic updates on is always among the first to meet a bad release. Every other update tool compared here lets an admin wait a set number of days first. Versioniq cannot, so an admin who wants that safety turns automatic updates off.

Three more rows depend on the waiting period, and the matrix rates them "no by construction": without a hold there is nothing to show as held back, nothing to release early, and nothing for a security fix to skip. This change specifies the minimum age first and the three on top of it.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers four rows.

| Row | Rating now | What is missing |
|---|---|---|
| `rel-min-age` | no | No cooldown: the nightly job picks the newest in-policy version whatever its age (`lib/Service/AutoUpdate/CandidateSelector.php:46-64`). |
| `rel-held-back-visible` | no, by construction | Depends on `rel-min-age`. Nothing is held back, and the Apps tab does not show which update the policy would take next. |
| `rel-min-age-exception` | no, by construction | Depends on `rel-min-age`. No way to release one version early. An admin can install any version by hand today. |
| `rel-security-bypass-min-age` | no, by construction | Depends on `rel-min-age`. The job does not look at advisories; they only raise a badge, a recommended version and a notification. |

### Demand

- `rel-held-back-visible`: feature request, https://github.com/renovatebot/renovate/discussions/44981. "Display updates/versions that have not yet met minimum release age thresholds", opened 2026-07-30, open.
- `rel-min-age-exception`: feature request, https://github.com/renovatebot/renovate/discussions/44513. "Can we automatically remove the outdated minimumReleaseAgeExclude configuration?", opened 2026-07-12, closed: per-version exclusions pile up after they stop mattering.
- `rel-security-bypass-min-age`: feature request, https://github.com/dependabot/dependabot-core/issues/15161. "PNPM: Allow security updates for PNPM despite minimumReleaseAge", opened 2026-05-28, closed.
- `rel-min-age`: no demand row. It is in the product's core area (releases), and three competitors rate it yes.

### Competitors rated yes (evidence quoted from the matrix)

- `rel-min-age`, Renovate rated yes: "lib/config/options/index.ts:2197 minimumReleaseAge, with minimumReleaseAgeBehaviour (lib/config/options/index.ts:2203) and a buffer (lib/config/options/index.ts:2211)". Dependabot rated yes: "cooldown holds updates for default-days, with separate semver-major, minor and patch days and include/exclude lists (updater/lib/dependabot/job/cooldown_definition.rb:14-19); since 2026-07-14 a 3-day default applies". Easy Updates Manager rated yes: "Premium, docs: 'Delay automatic updates' holds an update for a set time to skip short-lived releases (https://easyupdatesmanager.com/knowledge-base/delay-automatic-updates-premium/; readme.txt changelog 9.0.21 and 9.0.22 record the first-seen timestamp)."
- `rel-held-back-visible`, no competitor rated yes. Renovate is partial: held-back releases are kept as pending versions and shown as "the newest held version plus a count ... No eligibility date is shown (discussion 44981 is open)."
- `rel-min-age-exception`, Renovate rated yes: "An update held by minimumReleaseAge is listed under 'Pending Status Checks' with a checkbox that forces that one branch now (lib/workers/repository/dependency-dashboard.ts:559-564 ...), a one-off that needs no cleanup."
- `rel-security-bypass-min-age`, Renovate rated yes: "The vulnerabilityAlerts defaults set minimumReleaseAge to null for fix updates (lib/config/options/index.ts:2432-2436)". Dependabot rated yes: "the lowest security fix is chosen without the cooldown filter (common/lib/dependabot/package/package_latest_version_finder.rb:414-431) ... the options reference states cooldown does not apply to security updates."

## What changes

- An admin sets a minimum release age in days for the instance, and can override it per app on the app's policy. 0 means off, the default.
- The nightly job takes the newest in-policy version that is old enough. Newer versions wait. A version's age runs from its release date (`releasedAt`, from `releases-version-facts`), or from the first time Versioniq saw it when the source gives no date.
- A plan job works out, every six hours, for each app with a policy: the version the next run would install, and every newer version held back with the date it becomes eligible. The Automatic updates overview shows both, next to the blocked versions it shows today.
- On a held-back version an admin can pick "Release now". The next run may install it. The exception disappears on its own once that version is installed, reaches the minimum age, or is no longer listed, and each step is audited.
- When the advisory snapshot says the installed version is affected, the version the advisory recommends skips the hold. The policy level still applies. The audit row and the notification say that a security fix skipped the minimum age.

## Scope

In scope: the setting and its per-app override, the age rule and the first-seen record, the planner shared by the job and the plan job, the overview rows, the early release and its clean-up, the security bypass, tests.

Out of scope:
- A manual install. The picker shows the age and the eligibility date, but an admin who picks a version installs it. That is what Versioniq is for.
- A full preview of the next run, approval before it, and notice ahead of it. `autoupdate-proposed-updates` specifies those and can read the plan this change stores.
- Different ages for major, minor and patch releases. The policy level already bounds the step; a later change can split the age if someone asks.
- The release date itself. `releases-version-facts` specifies `releasedAt`.

## Impact

- New: `lib/Service/AutoUpdate/MinimumAgeRule.php`, `lib/Service/AutoUpdate/FirstSeenStore.php`, `lib/Service/AutoUpdate/AutoUpdatePlanner.php`, `lib/Service/AutoUpdate/AutoUpdatePlanStore.php`, `lib/Service/AutoUpdate/MinAgeExceptionStore.php`, `lib/BackgroundJob/AutoUpdatePlanJob.php`.
- Changed: `lib/BackgroundJob/AutoUpdateJob.php` (asks the planner), `lib/Service/Policy/Policy.php` (optional `minAgeDays`), `lib/Service/AutoUpdate/AutoUpdateSettingsStore.php` (instance default), `lib/Service/AutoUpdate/AutoUpdateNotifier.php` and `lib/Notification/Notifier.php` (the security note), `lib/Controller/ApiController.php` (setting, plan and exception routes), `appinfo/info.xml` (plan job), `src/components/AutoUpdateOverview.vue`, `src/components/PolicySelector.vue`, `src/App.vue` (the setting), `l10n/en` and `l10n/nl`.
- ADDED requirements in `auto-update-policies`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the plan is read-only data it can return, and "Release now" is a write it would gate like any other.

## Rollback

Revert the change. The setting is `auto_update_min_age_days`, the per-app age sits in the policy JSON as an optional field the old code ignores, and the plan, first-seen times and exceptions live in `autoupdate.plan`, `first_seen.{appId}` and `min_age_exception.{appId}`. Nothing else reads them. With the code gone the job installs the newest in-policy version again.
