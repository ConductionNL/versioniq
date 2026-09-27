---
kind: code
---

# Proposal: inventory-upgrade-readiness

## Why

Before an admin upgrades Nextcloud or PHP, they need to know which apps will stop working. Today Versioniq answers "does this version run on this server" for App Store releases, but only for the server that runs now. It says nothing about the next major, nothing about PHP, and a forge release that was never downloaded reads "unknown".

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers three rows that share one check.

| Row | Rating now | What is missing |
|---|---|---|
| `inv-server-upgrade-readiness` | no | No check of installed apps against the next server major. |
| `inv-compat-php` | no | The PHP requirement is only enforced during a real install, after download and file swap. Nothing lists the apps that will not run on the PHP version the server moves to. |
| `inv-compat-server` | partial, built | The missing half: a forge release that was never downloaded has no server range, so it reads unknown. App Store releases and cached forge releases are covered. |

### Demand

- `inv-compat-php`: feature request, https://github.com/nextcloud/updater/issues/750. "Check for php version and display non-compatible apps", opened 2026-04-27 and open. An earlier ask, server#12966, describes an app silently disabled on a PHP mismatch.
- `inv-server-upgrade-readiness` and `inv-compat-server`: no demand row. Both are in the product's core area (inventory).

### Competitors rated yes (evidence quoted from the matrix)

- `inv-server-upgrade-readiness`, Nextcloud rated yes: "apps/updatenotification/lib/Controller/APIController.php:71-131 fetches the app store for the next server version and returns missing vs available apps, shown as 'Apps missing compatible version' (apps/updatenotification/src/components/UpdateNotification.vue:23)".
- `inv-compat-php`, Nextcloud rated yes: "lib/private/App/AppStore/Fetcher/AppFetcher.php:88-106 drops store releases whose PHP spec does not match the running PHP_VERSION ... The updater does not list PHP-incompatible apps before a server upgrade (updater issue 750 is open)". Easy Updates Manager rated yes: Safe Mode "skips the update when the server does not meet it, and lists incompatible plugins on the plugin listing page" (https://easyupdatesmanager.com/knowledge-base/safe-mode-premium/).
- `inv-compat-server`, Nextcloud rated yes: "apps/appstore/lib/Controller/ApiController.php:133-135 computes missingDependencies and isCompatible ... Applies to the single latest release only". Easy Updates Manager rated yes: WordPress core shows "Compatible/Untested with your version of WordPress" (https://wordpress.org/documentation/article/manage-plugins/#plugin-compatibility-1).

## What changes

- App Store versions carry the release's PHP range next to its server range, and the version picker says whether a version runs on the PHP this server runs.
- For a forge release that is not in the artifact cache, Versioniq reads `appinfo/info.xml` at the release tag from the forge and takes the server and PHP range from it. The answer is stored per release, so each tag is read once.
- A new "Upgrade readiness" tab checks every installed app against a target server major and a target PHP version. Each app reads ready, needs an update first (with the version to take), blocked (no release supports the target), ships with the server, or unknown.
- `occ versioniq:readiness --server=<major> --php=<version>` prints the same report, as a table or JSON.

## Scope

In scope: the PHP range on App Store versions, the forge range lookup and its store, the readiness service, the tab, the command, tests.

Out of scope:
- Upgrading the server. That is the Nextcloud updater's job (matrix row `ins-server-core-update`, owner nextcloud/server, decided-no).
- Installing the versions the report names. `install-one-click-updates` specifies bulk updates.
- PHP extensions and database requirements. The App Store lists them, but no row or demand asks for them yet.

## Impact

- New: `lib/Service/Readiness/UpgradeReadinessService.php`, `lib/Service/Source/ForgeRangeStore.php`, `lib/Command/CheckReadiness.php`, `lib/BackgroundJob/UpgradeReadinessJob.php` (queued), `src/components/ReadinessPanel.vue`.
- Changed: `lib/Service/Source/AppStoreSource.php` (`normalizeVersions`), `lib/Service/Source/ForgeReleaseSource.php`, `lib/Service/Source/Forge.php` (a file-at-ref endpoint), `lib/Service/InstallerService.php` (`getAppVersions` stamps the forge range), `lib/Controller/ApiController.php` (two routes), `appinfo/info.xml`, `src/App.vue` (tab), `src/components/ServerCompatBadge.vue`, `l10n`.
- New capability spec `upgrade-readiness`; ADDED requirement in `version-management`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; a readiness tool fits there.

## Rollback

Revert the change. The forge ranges live in app config keys `forge_range.<sourceId>.<version>` and the last report in `readiness.results`; both are inert once the code is gone.
