---
kind: code
---

# Proposal: inventory-pins-checks-and-pending

## Why

Four rows of the competitor parity matrix (`openspec/parity/capabilities.json`, versioniq) are marked built, but their own notes say a part is missing. The boards VqApps and VqAutomatischeUpdates draw the whole row, and the boards are the UI canon (decision 130). This change plans the missing halves.

| Row | Built half | Missing half, as the board draws it |
|---|---|---|
| `pin-list` | Pins show as badges on the app cards. | No list or filter of pinned apps. VqApps draws a "Vastgezet 3" count in the status bar that filters the list. |
| `adv-last-checked` | The Apps tab says when the last advisory sweep completed. | Not whether it succeeded: a failed or partial feed read is only logged. VqApps draws "Adviezen gecontroleerd vandaag 06:00 uur" and "Updates gecontroleerd vandaag 06:02 uur" as the check line. |
| `aut-dashboard` | One overview lists every app with a policy and every failed attempt, with Retry (`src/components/AutoUpdateOverview.vue`). | Pending updates are not listed. VqAutomatischeUpdates draws "Geplande en tegengehouden updates" with "Gepland", "Wacht op goedkeuring" and "Mislukt". |
| `inv-source-shown` | Every card names its bound source. | The binding is not provenance: an unbound app reads App Store even when its version came from git or from outside Versioniq. VqApps draws "Deck is buiten Versioniq om naar 1.15.0 gezet." |

## What changes

- The Apps tab status bar gets a "Pinned" count that filters the list to pinned apps, as drawn.
- The advisory and update sweeps store their outcome (`ok`, `partial`, `failed`) with the apps they could not read. The check line says when the last sweep ran and whether it succeeded; a failed or partial sweep reads as such and names the apps.
- The automatic updates overview lists, per app with a policy, the newer version that is available now (from the stored availability sweep) and whether it is scheduled, held for approval, held by a pin or minimum age, or failed.
- Every card names where its installed version came from: the source of the last install Versioniq finalized, or "Changed outside Versioniq" when the installed version differs from that record.

## Scope

In scope: the four halves, strings, tests.

Out of scope: the approval flow itself (`autoupdate-proposed-updates`), the audit record of outside changes (`audit-external-changes`).

## Impact

- Changed: `lib/Service/Advisory/AdvisoryResultStore.php`, `lib/Service/Availability/AvailabilityResultStore.php`, `lib/BackgroundJob/AdvisoryRefreshJob.php`, `lib/BackgroundJob/AvailabilityRefreshJob.php`, `lib/Controller/ApiController.php` (`GET /api/advisories` and `GET /api/updates` return `status` and `errors`), `lib/Service/InstallerService.php` (`getInstalledApps` adds `provenance`), `src/App.vue` (status bar, check line, card), `src/components/AutoUpdateOverview.vue`, `src/components/AppSourceBadges.vue`, l10n.
- ADDED requirements in `version-pinning`, `security-advisory-correlation`, `auto-update-policies` and `external-sources`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. The added config keys (`advisory.results.status`, `availability.results.status`) are inert without the code.

## Board note

VqApps draws the check line in its success state only. The failed and partial states this change adds need a small board touch-up (a board lane, Fable) before the build starts.
