---
kind: code
---

# Proposal: autoupdate-run-batching

## Why

The nightly job installs every qualifying update it finds, one app after another. Two things are missing for an admin who runs related apps. Apps that only work at matching versions, such as a register app and the apps built on it, cannot be told to move together: one can update while its partner fails, and the instance wakes up half moved. And nothing caps a run. After a quiet month, twenty updates can land in one night, and if something breaks the admin has twenty suspects.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers two rows that both shape what one nightly run does.

| Row | Rating now | What is missing |
|---|---|---|
| `aut-grouping` | no | Each app installs on its own (`AutoUpdateJob.php:105-120`). There is no group of apps that moves together or stops together. |
| `aut-rate-limit` | no | Every app with a policy is processed in every run. There is no cap per run or per week. |

### Demand

No demand row in the matrix for either row.

### Competitors rated yes (evidence quoted from the matrix)

- `aut-grouping`, two rated yes. Renovate: "lib/config/options/index.ts:2669 groupName and group presets (lib/config/presets/internal/group.preset.ts), minimumGroupSize (lib/config/options/index.ts:440)." Dependabot: "Core and service: groups by pattern, dependency type or update type, for version and security updates, and multi-ecosystem groups (updater/lib/dependabot/dependency_group_engine.rb, https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)."
- `aut-rate-limit`, two rated yes. Renovate: "lib/config/options/index.ts:2287 prHourlyLimit, lib/config/options/index.ts:2294 prConcurrentLimit, lib/config/options/index.ts:2301 branchConcurrentLimit and lib/config/options/index.ts:2281 commitHourlyLimit." Dependabot: "Service config: open-pull-requests-limit caps concurrent version update pull requests (default 5) (https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)."

## What changes

- An admin makes an update group: a name and an ordered list of apps. The job installs a group's due updates in that order, in the same run.
- A group moves together or not at all. When one member's source cannot be read, when one member is pinned, or when one member's candidate failed before, the whole group waits. When an install in the group fails, the job stops the group there and does not install the rest.
- A group run ends in one notification that lists every member and its outcome.
- An admin can cap how many updates one run installs, and how many run in any seven days. A group counts as its members, and a group that does not fit waits whole.
- Updates the cap held back go first in the next run. The overview lists them as waiting for the limit.
- Before installing anything, the job now builds the run as a list of units (single apps and groups). `autoupdate-proposed-updates` reads that same list for its preview.

## Scope

In scope: groups (store, API, form), the group rules in the job, the per-run and per-week caps, the waiting list, the group notification, the planning step, tests.

Out of scope:
- Rolling back the members a failed group already installed. `install-bulk-rollback` specifies rollback of several apps.
- Days per kind of update. `autoupdate-schedules` specifies them; a group member is only planned on a day its update kind is due.
- Security fixes that should not wait for the cap. `autoupdate-security-first` specifies that.
- A preview or an approval of the run. `autoupdate-proposed-updates` specifies those.

## Impact

- New: `lib/Service/AutoUpdate/UpdateGroup.php`, `lib/Service/AutoUpdate/UpdateGroupStore.php`, `lib/Service/AutoUpdate/AutoUpdatePlanner.php`, `lib/Service/AutoUpdate/AutoUpdatePlan.php`, `src/components/UpdateGroupsPanel.vue`.
- Changed: `lib/BackgroundJob/AutoUpdateJob.php` (plan, then execute), `lib/Service/AutoUpdate/AutoUpdateSettingsStore.php` (two limit keys and the waiting list), `lib/Service/AutoUpdate/AttemptLedger.php` (count attempts since a time), `lib/Service/AutoUpdate/AutoUpdateNotifier.php` (group summary), `lib/Notification/Notifier.php` (render it), `lib/Controller/ApiController.php` (`GET /api/policies`, `PUT /api/auto-update/settings`, new `PUT /api/auto-update/groups`), `src/App.vue`, `src/components/AutoUpdateOverview.vue`, `l10n/en.json`, `l10n/nl.json`.
- Capability spec `auto-update-policies`: two ADDED requirements.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; groups and limits are policy data it can read.

## Rollback

Revert the change. Groups live in app config key `auto_update.groups`, the limits in `auto_update.max_per_run` and `auto_update.max_per_week`, the waiting list in `auto_update.deferred`. Without the code nothing reads them, and the job installs app by app as before. They can stay or be deleted with `occ config:app:delete`.
