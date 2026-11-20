---
kind: code
---

# Proposal: inventory-lifecycle-signals

## Why

An app can stop being maintained while it keeps running. Its publisher pulls it from the App Store, archives the repository, or renames it, and the admin finds out when it breaks on the next server upgrade. The server itself reaches end of life on a known date, and Versioniq says nothing about that either. Tenders ask for life cycle management that acts before an end-of-support date passes, not after.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers three rows about the life cycle of what is installed.

| Row | Rating now | What is missing |
|---|---|---|
| `inv-deprecated-apps` | no | No deprecation, abandonment or replacement signal is read or shown. |
| `inv-end-of-support` | no | No end-of-support date for the server or any app, and no warning before one passes. |
| `ins-replace-successor` | no | Versioniq changes versions of one app id. It cannot move an install to a renamed or successor app. Its own rename from `app_versions` needed repair steps for that. |

### Demand

- `inv-end-of-support`: tender, https://www.tenderned.nl/aankondigingen/overzicht/411198. Compute, Storage en Backup, requirement 60233: life cycle management such as end of life and end of support is handled proactively. Also tender 415623, requirement 175385 (EOL and EOS).
- `ins-replace-successor`: competitor changelog, https://github.com/renovatebot/renovate/pull/45371 (a Renovate replacement preset added on 2026-09-18). The row is in the core area (install).
- `inv-deprecated-apps`: no demand row; three competitors rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- `inv-deprecated-apps`, Renovate rated yes: "lib/workers/repository/dependency-dashboard.ts:472 Deprecations / Replacements section, lib/config/options/index.ts:2218 abandonmentThreshold with an Abandoned Dependencies section at dependency-dashboard.ts:724". OSV-Scanner rated yes: "experimental-flag-deprecated-packages reports deprecated and yanked packages from deps.dev (cmd/osv-scanner/internal/helper/flags.go:195, docs/package-deprecation.md:143-156)". Easy Updates Manager rated yes: "Check plugins flags plugins removed from the WordPress directory and Unmaintained plugins flags plugins not updated in a year (https://easyupdatesmanager.com/knowledge-base/check-plugins-premium/, https://easyupdatesmanager.com/knowledge-base/check-for-unmaintained-plugins/)".
- `ins-replace-successor`, Renovate rated yes: "replacementName and replacementVersion (lib/config/options/index.ts:1732-1763) raise a replacement PR, with replacement presets maintained in lib/config/presets/internal/replacements.preset.ts".
- `inv-end-of-support`: no competitor rated yes. Nextcloud is partial for the server only: "the update server's eol flag ... shows 'The version you are running is not maintained anymore' ... after the fact and without a date. Apps have no end of support data".

## What changes

- The availability sweep also records life cycle signals per app: removed from the App Store, repository archived on the forge, no release in the last twelve months, and no release that supports a server major that is still maintained.
- Versioniq reads the end-of-life dates of Nextcloud server majors from a configurable feed, shows the date for the running server on the Advisories tab, and notifies admins 90 and 30 days before it passes.
- An app counts as end of support when none of its releases supports a server major that is still maintained, and its card says so.
- A successor list names apps that were renamed or replaced. Versioniq ships the known entries and an admin can add one. When the successor can be installed, the old app's card offers "Replace with {successor}": Versioniq installs the successor through the normal install path and then disables the old app. It never deletes the old app's data.

## Scope

In scope: the four signals, the server end-of-life feed and its warnings, the app end-of-support rule, the successor list, the replace action, tests.

Out of scope:
- Moving data from the old app to the successor. That is the successor's own migration, as Versioniq's own rename showed.
- Release dates in the version picker. `releases-version-facts` specifies them; this change reads the same date field.
- Updating the server. That is the Nextcloud updater's job (row `ins-server-core-update`, decided-no).

## Impact

- New: `lib/Service/Lifecycle/LifecycleSignals.php`, `lib/Service/Lifecycle/ServerEolFeed.php`, `lib/Service/Lifecycle/SuccessorList.php`, `lib/Settings/successors.json`, `lib/BackgroundJob/ServerEolWarningJob.php`, `src/dialogs/ReplaceAppDialog.vue`.
- Changed: `lib/Service/Availability/AvailabilityService.php` (from `inventory-pending-updates`), `lib/Service/Source/AppStoreSource.php` (store removal), `lib/Service/Source/ForgeReleaseSource.php` (archived flag), `lib/Controller/ApiController.php` (successor routes and the replace action), `lib/Notification/Notifier.php` (two subjects), `lib/Service/Settings/InstanceSettings.php` (feed address), `src/App.vue` (card signals), `src/components/AdvisoriesPanel.vue` (server date), `l10n`.
- New capability spec `lifecycle-signals`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. The signals live in the availability snapshot, the successor entries and feed address in app config keys `successors.custom` and `lifecycle.eol_feed`; all are inert without the code.
