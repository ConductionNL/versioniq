---
kind: code
---

# Proposal: install-one-click-updates

## Why

Updating one app to its newest version takes three steps in Versioniq: choose the app, pick the top version, press Update. Updating ten apps takes thirty. And when an advisory names the safe version, the admin reads it as text on the card and then goes looking for it in the version list. Nextcloud's own Apps page updates one app, or all of them, in one click.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers three rows that share the install flow.

| Row | Rating now | What is missing |
|---|---|---|
| `ins-upgrade-latest` | partial, built | The missing half: there is no one-click "update to latest". A nightly "All" policy does it unattended, but not on request. |
| `ins-update-all` | no | No "update all" action. The nightly job is scheduled automation, not an admin action. |
| `adv-one-click-fix` | no | The recommended safe version is text only (`src/App.vue:2257-2258`); the admin must find and install it by hand. |

### Demand

No demand row for these three. `ins-upgrade-latest` and `ins-update-all` are rated yes by four competitors each and sit in the core area (install); `adv-one-click-fix` is rated yes by two.

### Competitors rated yes (evidence quoted from the matrix)

- `ins-upgrade-latest`: Nextcloud, "Update action per app (apps/appstore/src/actions/actionUpdate.ts, POST /api/v1/apps/update in apps/appstore/lib/Controller/ApiController.php:253-271); occ app:update <id>". Renovate, "the dashboard checkbox creates an awaited update now (lib/workers/repository/dependency-dashboard.ts:517-523)". Dependabot, "a pull request bumps the dependency to its latest allowed version ... merging it is the one action". Easy Updates Manager, WordPress core "update now" per plugin (https://wordpress.org/documentation/article/manage-plugins/#manual-plugin-update-from-the-plugins-page).
- `ins-update-all`: Nextcloud, "'Update all applications' button and dialog (apps/appstore/src/views/AppstoreManage.vue:58-66, apps/appstore/src/components/UpdateAllDialog.vue:48-60) ... Known v34 papercut: password asked per app, https://github.com/nextcloud/server/issues/61703". Renovate, "groupName ... puts every update in one PR". Dependabot, "groups with patterns such as * combine every pending update into one pull request". Easy Updates Manager, WordPress core "Select All and Update Plugins on Dashboard > Updates (https://wordpress.org/documentation/article/dashboard-updates-screen/)".
- `adv-one-click-fix`: Renovate, "A security PR to the fixed version is created immediately, bypassing schedule and concurrency limits (lib/config/options/index.ts:2432-2443)". Dependabot, "Create Dependabot security update on the alert page opens the fixing pull request (https://docs.github.com/en/code-security/how-tos/manage-security-alerts/manage-dependabot-alerts/view-dependabot-alerts)".

## What changes

- A card with a pending update, from the snapshot `inventory-pending-updates` specifies, gets an "Update to {version}" button. It opens one confirmation that shows the release notes between the two versions and then runs the normal install.
- The Apps tab gets "Update all ({n})": a dialog lists every app with a pending update, lets the admin untick any, asks for the password once, and runs the installs one after the other, showing each outcome as it lands. Pinned apps are listed but unticked, with the reason.
- A card whose advisory names a safe version gets "Update to safe version {version}", which runs the same confirmation for that version.
- Every one of these goes through the existing install endpoint, so every guard still applies: allowlist, integrity checks, the downgrade guard, the pin guard, backup and restore, audit.

## Scope

In scope: the three buttons, the update-all dialog and its sequential run, tests.

Out of scope:
- A new install path. All three call `POST /api/app/{appId}/versions/{version}/install`.
- Rolling back a batch. `install-bulk-rollback` specifies that.
- Live progress inside one install. `install-live-progress` specifies that; this change shows one outcome per app.

## Impact

- New: `src/dialogs/QuickUpdateDialog.vue`, `src/dialogs/UpdateAllDialog.vue`, `src/utils/updateBatch.ts`.
- Changed: `src/App.vue` (card buttons, the Update all button), `l10n`. No backend change: the install endpoint and the snapshot already carry what is needed.
- ADDED requirements in `version-management` and `security-advisory-correlation`.

### MCP coverage

No MCP surface in this change: installing stays a human action with a password; `admin-mcp-assistant` keeps its tools read-only.

## Rollback

Revert the change. It stores nothing.
