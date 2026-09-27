---
kind: code
---

# Proposal: inventory-pending-updates

## Why

An admin who wants to know which apps are behind has to open every app on the Apps tab, one at a time. The card shows the app's name, id and description, but not the version it runs, and nothing says a newer one exists. Every tool Versioniq is compared with answers "what is out of date" on one screen.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers five rows that share one screen and one service.

| Row | Rating now | What is missing |
|---|---|---|
| `inv-list-installed` | partial, built | The card does not show the installed version, although `openspec/specs/version-management/spec.md` ("List Installed Apps") already requires it. |
| `inv-update-available` | no | Nothing marks an app that has a newer version. |
| `inv-version-lag` | no | Nothing measures how far an app is behind, or flags it against a policy such as "at most one release behind". |
| `pin-still-notify` | partial, built | A pinned app's newer versions show only when its version list is opened. |
| `adm-cli-machine-output` | partial, built | `occ versioniq:versions --json` covers one app. No command lists pending updates across all apps. |

### Demand

- `inv-version-lag`: tender, https://www.tenderned.nl/aankondigingen/overzicht/265421. Selectie VTH/Zaaksysteem, requirement 202694: integrations must keep working after updates, on condition of N-1 (newest standard minus one version). Also tender 411343, requirement 107642: current and supported versions are guaranteed.
- `pin-still-notify`: feature request, https://wordpress.org/support/topic/block-auto-update-but-not-the-update-notification-of-avaiable-update/. Block the automatic update but keep the notice that one exists.
- `adm-cli-machine-output`: feature request, https://github.com/nextcloud/server/issues/46056. "Add customisable output format to occ app:update --showonly", open since 2024-06-23: plain text output must be parsed by scripts.

### Competitors rated yes (evidence quoted from the matrix)

- `inv-list-installed`, all five rated yes. Nextcloud: "apps/appstore/src/views/AppstoreManage.vue:33 (Installed category) with the version column at apps/appstore/src/components/AppTable/AppTableRow.vue:79". Renovate: "lib/workers/repository/package-files.ts:39 renders a Detected Dependencies section ... with its current version". Dependabot: the dependency graph lists every dependency with its version (https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/secure-your-dependencies/explore-dependencies). OSV-Scanner: "all-packages with format json lists every package with its version (cmd/osv-scanner/internal/helper/flags.go:182)". Easy Updates Manager: "includes/MPSUM_Plugins_List_Table.php:620-623 prints each plugin's installed version".
- `inv-update-available`, three rated yes. Nextcloud: "apps/appstore/src/views/AppstoreManage.vue:39 lists apps with a pending update under the Updates category". Renovate: "lib/workers/repository/dependency-dashboard.ts:500-610 lists pending, awaiting-schedule, rate-limited and open update branches per dependency". Easy Updates Manager: Dashboard > Updates lists every plugin with a newer version (https://wordpress.org/documentation/article/dashboard-updates-screen/).
- `pin-still-notify`, Renovate rated yes: "dependencyDashboardApproval (lib/config/options/index.ts:911) keeps a blocked update listed under Pending Approval on the dashboard (lib/workers/repository/dependency-dashboard.ts:500-507)".
- `adm-cli-machine-output`, Renovate rated yes: "dryRun lookup ... and reportType file writes a JSON report of each repository's packageFiles, filled by the lookup stage with the pending updates per dependency (lib/workers/repository/index.ts:121-124)".
- `inv-version-lag`, no competitor rated yes. Renovate is partial: "lib/workers/repository/process/libyear.ts:67-95 computes the lag in libyears per dependency ... nothing flags a breach of a policy such as N-1".

## What changes

- A background sweep works out, per installed app, the installed version, the newest version its bound source offers, the newest one this server can run, and how many release lines the app is behind. It stores the result as a snapshot, the same way advisories are stored.
- `GET /api/updates` returns that snapshot with the time it was taken. The page never lists versions live for every app, because that is the request that timed out in issue #160.
- Every app card shows the installed version. An app with a newer version gets an "Update available" badge with that version. A pinned app keeps the badge, marked as held by the pin.
- An admin can set how many release lines an app may fall behind. An app past that limit is flagged "Outside the update policy". The Apps tab gets a filter for apps with an update, and for apps outside the policy.
- `occ versioniq:updates` lists pending updates for every app, as a table or as JSON, from the snapshot or from a fresh sweep.

## Scope

In scope: the sweep, its snapshot store, the endpoint, the card badges and filter, the lag limit setting, the `occ` command, tests.

Out of scope:
- Installing from the badge. `install-one-click-updates` specifies "Update to latest" and "Update all".
- A notification when a new version appears. `releases-new-release-alerts` specifies that and reads this snapshot.
- Pre-release versions. The sweep counts only plain `major.minor.patch` versions, the same rule `CandidateSelector` applies. `releases-channel-and-prereleases` specifies a channel choice.

## Impact

- New: `lib/Service/Availability/AvailabilityService.php`, `lib/Service/Availability/AvailabilityResultStore.php`, `lib/BackgroundJob/AvailabilityRefreshJob.php`, `lib/Command/ListUpdates.php`.
- Changed: `lib/Controller/ApiController.php` (one route), `lib/Service/Settings/InstanceSettings.php` (one key), `appinfo/info.xml` (job and command), `src/App.vue` (card and filter), `src/components/InstanceSettingsPanel.vue` (the limit), `l10n/en` and `l10n/nl`.
- New capability spec `pending-updates`; ADDED requirement in `cli-commands`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one and can read this snapshot.

## Rollback

Revert the change. The snapshot lives in two app config keys (`availability.results`, `availability.results.checkedAt`) and the limit in one (`update.max_lines_behind`). Nothing else reads them, and they can stay or be deleted with `occ config:app:delete`.
