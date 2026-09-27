---
kind: code
---

# Proposal: autoupdate-proposed-updates

## Why

The nightly job decides what to install at the moment it runs, and throws the decision away if it installs nothing (`AutoUpdateJob.php:156`). An admin learns what happened only afterwards, from a success or failure notification. Three things follow. Nobody can see what the policy would install tonight. Nobody can stop one update while letting the rest go. And a functional administrator who must prepare users for a new version hears about it the morning after.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers three rows that share one record: a proposed update, worked out ahead of the window.

| Row | Rating now | What is missing |
|---|---|---|
| `aut-approval` | no | The job installs the candidate directly (`AutoUpdateJob.php:166`). There is no proposed-update queue and no approval step. |
| `aut-dry-run` | no | The policy's candidate per app is computed only inside the job and never exposed. The Dry run toggle on the Apps tab (`src/App.vue:2063-2070`) evaluates one manual install, not the policy. |
| `aut-advance-notice` | no | Admins are notified only after an install succeeded or failed (`AutoUpdateNotifier.php:48`, `:63`). Nothing announces an update before it runs. |

### Demand

- `aut-dry-run`: feature request, https://github.com/dependabot/dependabot-core/issues/15028. "Native dry-run or similar flag in dependabot.yml", opened 2026-05-15, open: run the full pipeline but suppress every write.
- `aut-advance-notice`: tender, https://www.tenderned.nl/aankondigingen/overzicht/416075. Marktconsultatie Contract- en Leveranciersmanagementsysteem, requirement 172745 (also 173072): release notes available at least 4 weeks (or 14 days) before a planned update, describing consequences and changes to integrations.
- `aut-approval`: no demand row in the matrix.

### Competitors rated yes (evidence quoted from the matrix)

- `aut-approval`, two rated yes. Renovate: "lib/config/options/index.ts:911 dependencyDashboardApproval holds updates under "Pending Approval" until a checkbox is ticked (dependency-dashboard.ts:500-507)." Dependabot: "Closed service: an update is a pull request that a person reviews and merges; reviewers can be requested per ecosystem (https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)."
- `aut-dry-run`, Renovate rated yes: "dryRun extract, lookup or full (lib/config/options/index.ts:597-603) runs the whole pipeline with the current config and logs the branches and PRs it would create instead of writing them; lib/config-validator.ts validates a changed config first."
- `aut-advance-notice`, no competitor rated yes. Renovate is partial: "With automergeSchedule (lib/config/options/index.ts:1037) the PR, carrying release notes, opens at once (prCreation, lib/config/options/index.ts:2268) and is merged only in the later window, and 'Awaiting Schedule' lists what is queued (lib/workers/repository/dependency-dashboard.ts:518). The lead time follows the windows, not a set number of days before each update." Easy Updates Manager is partial: a weekly or monthly e-mail lists pending updates and a delay holds automatic updates, but "the e-mail is not tied to the scheduled run and the docs mention no release notes in it."

## What changes

- After each availability sweep, Versioniq works out a proposed update per app with a policy: the version the policy would install at the next due window, from which version, what kind of step it is, and when it is planned. It stores the proposals with the time they were computed.
- The Apps tab lists the proposed updates, with the release notes of each target version and why other apps have none (pinned, blocked, not due, waiting for the limit).
- `occ versioniq:auto-update --dry-run` plans the next run live, prints it, and installs nothing.
- An admin can set, per app, that an automatic update needs approval. The job then installs only a proposal an admin approved for that exact version. An admin can also decline a version, and the job skips it until the decline is withdrawn.
- An admin can set a notice period in days. A proposal is announced to the admins, and optionally to a group of functional administrators, with the release notes and the planned date. The job installs it only once the notice period has passed.
- Every approve, decline and withdrawal writes an audit entry.

## Scope

In scope: the proposal step and store, the preview on the page, the dry-run command, per-app approval, declines, the notice period and announcement, audit entries, tests.

Out of scope:
- The availability sweep itself. `inventory-pending-updates` specifies it; this change reads the version lists it already fetches.
- The planning step. `autoupdate-run-batching` adds `AutoUpdatePlanner`; this change calls it for the next opening instead of now.
- The next run time per kind. `autoupdate-schedules` adds `AutoUpdateSchedule::nextRun()`; this change uses it for the planned date.
- Breaking-change flags and known issues on a version. `releases-version-facts` specifies them; an announcement shows them once they exist.
- Installing from the proposal list by hand. `install-one-click-updates` specifies that.

Depends on: `inventory-pending-updates`, `autoupdate-run-batching` and `autoupdate-schedules`.

## Impact

- New: `lib/Service/AutoUpdate/ProposedUpdate.php`, `lib/Service/AutoUpdate/ProposalStore.php`, `lib/Service/AutoUpdate/ProposalService.php`, `lib/Command/AutoUpdate.php`, `src/components/ProposedUpdatesPanel.vue`.
- Changed: `lib/Service/Availability/AvailabilityService.php` (hand the version lists to the proposal step), `lib/BackgroundJob/AvailabilityRefreshJob.php` (run it), `lib/BackgroundJob/AutoUpdateJob.php` (honour notice, approval and declines), `lib/Service/Policy/Policy.php` (`requireApproval`), `lib/Service/AutoUpdate/AutoUpdateSettingsStore.php` (notice days and group), `lib/Service/AutoUpdate/AutoUpdateNotifier.php` and `lib/Notification/Notifier.php` (`auto_update_planned`), `lib/Controller/ApiController.php` (four routes, two fields), `appinfo/info.xml` (command), `src/App.vue`, `src/components/PolicySelector.vue`, `l10n/en.json`, `l10n/nl.json`.
- Capability specs: `auto-update-policies` three ADDED requirements; `cli-commands` one ADDED requirement.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the proposed updates are what it can read to answer "what installs tonight". Approving stays with the admin on the page.

## Rollback

Revert the change. Proposals live in app config key `auto_update.proposals`, the notice settings in `auto_update.notice_days` and `auto_update.notice_group`, and `requireApproval` is an extra field in `policy.{appId}` that the old `Policy::fromArray()` ignores. Without the code the job installs as before, without notice or approval. The keys can be deleted with `occ config:app:delete`.
