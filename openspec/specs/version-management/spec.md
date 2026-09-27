---
status: implemented
---

# Version Management Specification

**Status**: implemented
**Standards**: Nextcloud App Store API v1, GitHub REST API v2022-11-28, OCP\App\IAppManager
**Feature tier**: MVP

## Purpose

Version management is the core capability of Versioniq. It allows Nextcloud administrators to view all available versions of any installed app, select a specific version (older or newer), and install it — replacing the currently installed version. This enables rollback after broken updates, testing compatibility with specific versions, and controlled upgrades.

Each app may be bound to a single source (App Store or external such as GitHub releases) that is authoritative for its version listings. See the companion [external-sources spec](../external-sources/spec.md) for how external sources are validated and installed.
## Requirements
### Requirement: List Installed Apps [MVP]

The system MUST display all currently installed Nextcloud apps with their name, current version, description, and icon. Core/always-enabled apps SHOULD be visually distinguished but still listed. Installed apps that are disabled, and apps bound to a source but not installed yet, MUST be listed too, each marked with its state (`disabled`, `notInstalled`), so their versions can be listed and installed from the page.

#### Scenario: Admin views installed apps

@e2e tests/e2e/shell.spec.ts

- GIVEN an admin user opens Versioniq via Settings → Administration → Versioniq
- WHEN the app list loads
- THEN all installed apps MUST be displayed as selectable cards
- AND each card MUST show: app name, current version, icon, and summary
- AND apps MUST be sorted alphabetically
- AND the Versioniq app itself MUST be excluded from the list

#### Scenario: Disabled and bound but not installed apps are listed

@e2e exclude needs a disabled app and a bound, uninstalled app with a reachable release source on the test instance; the list and its states are covered by tests/unit/Service/InstallerServiceAppListTest.php.

- GIVEN `calendar` is installed and disabled, and `hermiq` is bound to `github:ConductionNL/hermiq` but not installed
- WHEN the app list loads
- THEN `calendar` MUST be listed with state `disabled` and its installed version
- AND `hermiq` MUST be listed with state `notInstalled` and no installed version
- AND each card MUST show that state, and choosing the app MUST open its version list
- AND after a live install of an app Nextcloud has not enabled, the result MUST offer a link to enable it

#### Scenario: Non-admin user is blocked

@e2e tests/e2e/shell.spec.ts

- GIVEN a non-admin user
- WHEN they open Settings
- THEN the Administration section (and therefore Versioniq) SHALL NOT be visible to them at the framework level
- AND there MUST be no top-level navigation entry or page route through which they could reach the UI
- AND if the user calls any Versioniq API endpoint directly
- THEN the system MUST return a "Forbidden" response
- AND no app data MUST be returned from the API

### Requirement: Fetch Available Versions [MVP]

The system MUST query the **bound source** for an app to retrieve all available releases. If no source is bound, the App Store is queried by default. Versions MUST be filtered by compatibility with the current Nextcloud version and update channel. Each App Store version entry MUST carry `serverCompatible`: true or false from the release's `platformVersionSpec` against the running server, or null when the release states no range.

#### Scenario: Bound source is queried first

@e2e tests/e2e/version-management.spec.ts

- GIVEN an admin selects app `openregister` (bound to `github:ConductionNL/openregister`)
- WHEN the version list loads
- THEN the system MUST fetch from `https://api.github.com/repos/ConductionNL/openregister/releases`
- AND the App Store MUST NOT be queried as a fallback in this request

#### Scenario: Unbound app falls through to App Store

@e2e tests/e2e/versions.spec.ts

- GIVEN an admin selects app `someapp` (no binding present)
- WHEN the version list loads
- THEN the system MUST fetch from the Nextcloud App Store endpoints

#### Scenario: View versions for an app

@e2e tests/e2e/versions.spec.ts

- GIVEN an admin selects app "OpenRegister" from the list
- WHEN the version list loads
- THEN the system MUST show all available versions from the bound source
- AND the currently installed version MUST be highlighted
- AND versions incompatible with the current Nextcloud version MUST be marked as incompatible
- AND each version MUST show: version number, release date, minimum NC version, maximum NC version

#### Scenario: App store API is unreachable

@e2e exclude the public App Store cannot be made unreachable from an e2e run; the error path is unit-tested in AppStoreSourceTest.

- GIVEN the Nextcloud App Store API is down or unreachable
- WHEN an admin tries to fetch versions
- THEN the system MUST show an error message "Could not fetch versions from the app store"
- AND the system MUST NOT crash or show a blank page

#### Scenario: Respect update channel

@e2e exclude the server release channel cannot be varied within an e2e run; channel filtering is unit-tested.

- GIVEN the Nextcloud instance is on the "stable" update channel
- WHEN fetching versions
- THEN beta/nightly releases SHOULD be filtered out or marked as non-stable

---

### Requirement: Source binding [MVP]

When an app is installed via the version manager, the source it was installed from MUST be persisted as a binding under app config key `source.{appId}`. Future version queries for that app MUST default to the bound source. Apps installed via Nextcloud's normal app-install flow (outside Versioniq) have no binding and default to App Store.

#### Scenario: Install from App Store leaves no GitHub binding

@e2e exclude the signed App Store install path is not driven in e2e (see version-management notes); binding-write logic is unit-tested.

- GIVEN an admin installs `someapp@1.2.0` from the App Store via Versioniq
- WHEN the install completes
- THEN `versioniq.source.someapp` MUST either be unset or set to `{kind: "appstore"}`
- AND future version queries for `someapp` MUST hit the App Store

#### Scenario: Install from GitHub binds to that source

@e2e tests/e2e/version-management.spec.ts

- GIVEN an admin installs `openregister@2.5.0` from `github:ConductionNL/openregister`
- WHEN the install completes
- THEN `versioniq.source.openregister` MUST be set to `{kind: "github-release", owner: "ConductionNL", repo: "openregister", assetPattern: "*.tar.gz", boundAt: ISO-8601-timestamp}`
- AND the next call to `GET /api/app/openregister/versions` MUST query the GitHub source, not the App Store

#### Scenario: Re-binding overwrites previous binding

@e2e tests/e2e/version-management.spec.ts

- GIVEN `versioniq.source.openregister` is currently bound to `github:ConductionNL/openregister`
- WHEN the admin installs `openregister@2.5.0` from the App Store via the source-picker
- THEN `versioniq.source.openregister` MUST be updated to `{kind: "appstore"}`
- AND the next version query MUST hit the App Store

---

### Requirement: Explicit source override [MVP]

The version-list and install endpoints MUST accept an optional `source` parameter that overrides the bound source for that single request without changing the binding.

#### Scenario: One-off query without binding change

@e2e tests/e2e/version-management.spec.ts

- GIVEN `openregister` is bound to `github:ConductionNL/openregister`
- WHEN the admin calls `GET /api/app/openregister/versions?source=appstore`
- THEN the response MUST contain App Store versions
- AND `versioniq.source.openregister` MUST remain unchanged

---

### Requirement: Install Specific Version [MVP]

The system MUST allow an admin to install any available version of an app, replacing the currently installed version. This operation MUST require password confirmation for security. Every install failure MUST return a structured payload — independent of the debug toggle — carrying `stage` (the last installer stage reached, e.g. `backup`, `download`, `checksum`, `archive-extracted`, `info-validated`, `finalize`), `category` (one of `preflight_permission | download | checksum_mismatch | extract | appid_mismatch | version_mismatch | incompatible | finalize | unknown`), and a `hint` (a human, actionable, translatable remediation string). The HTTP status MUST reflect the category and MUST NOT be a blanket 500 for classified failures; 500 is reserved for `unknown`/unexpected errors.

#### Scenario: Install an older version (rollback)

@e2e tests/e2e/version-management.spec.ts

- GIVEN OpenRegister is currently at version 2.5.0
- WHEN the admin selects version 2.3.0 and confirms their password
- THEN the system MUST download version 2.3.0 from the app store
- AND replace the current app files with the downloaded version
- AND show a success message with the new version number
- AND the app MUST remain enabled after the version change
- AND the payload `installStatus` MUST be `installed`

#### Scenario: Install a newer version (upgrade)

@e2e tests/e2e/version-management.spec.ts

- GIVEN OpenRegister is currently at version 2.3.0 and version 2.5.0 is available
- WHEN the admin selects version 2.5.0 and confirms
- THEN the system MUST download and install version 2.5.0
- AND any database migrations for the new version MUST be triggered

#### Scenario: Installation fails

@e2e tests/e2e/forge.spec.ts

- GIVEN a download or extraction error occurs during installation
- WHEN the admin attempts to install a version
- THEN the system MUST show a clear error message
- AND the previous version MUST remain intact (no partial installs)
- AND the response payload MUST include `stage`, `category`, and `hint` fields even when debug mode is OFF
- AND the `hint` MUST be an actionable, translatable remediation string

#### Scenario: Failure category drives HTTP status

@e2e tests/e2e/version-management.spec.ts

- GIVEN an install fails with category `preflight_permission`
- WHEN the response is returned
- THEN the HTTP status MUST NOT be 500
- AND the status MUST be a category-appropriate code (e.g. 409 for `preflight_permission`, 422 for `incompatible`/`version_mismatch`/`appid_mismatch`/`checksum_mismatch`)
- AND a genuinely unexpected error MUST map to category `unknown` with HTTP 500

#### Scenario: Frontend surfaces the structured message

@e2e exclude a real UI-triggered install resets opcache and 503s the mod_php test image; the result-panel rendering is covered by App.vue vitest.

- GIVEN the backend returns a failure payload with `message` and `hint`
- WHEN the frontend renders the install result
- THEN the frontend MUST display the backend `message`/`hint` rather than the generic OCS meta message
- AND the result card MUST render the `stage`, `category`, and `hint`

#### Scenario: Password confirmation required

@e2e exclude the e2e admin session is always password-confirmed; the un-confirmed rejection cannot be produced in a browser. Enforced by the PasswordConfirmationRequired attribute (route-auth gate).

- GIVEN an admin clicks "Install" for a specific version
- WHEN the install action is triggered
- THEN the system MUST require password re-confirmation before proceeding
- AND the install MUST NOT proceed without valid password confirmation

### Requirement: Debug Mode [MVP]

The system MUST provide a debug mode that returns detailed installation logs for troubleshooting. Debug mode controls diagnostic verbosity only. The install endpoint MUST accept an independent boolean `dryRun` parameter that triggers the dry-run path regardless of `debug`. For backward compatibility, `debug=1` **without** an explicit `dryRun` parameter MUST keep implying a dry-run (deprecated, documented); `debug=1&dryRun=0` MUST perform a real install with verbose diagnostics.

#### Scenario: Enable debug output

@e2e exclude a real install via the web API 503s the test image opcache; the debug toggle is covered by App.vue vitest and the occ path.

- GIVEN an admin enables the "Debug" toggle before installing
- WHEN the installation completes (success or failure)
- THEN the response MUST include detailed logs: download URL, file sizes, extraction steps, any warnings

#### Scenario: Verbose real install

@e2e exclude a real install via the web API 503s the test image opcache; the debug/dryRun resolution is unit-tested.

- WHEN the admin installs a version with `debug=1&dryRun=0`
- THEN a real install MUST be performed
- AND the response MUST include the detailed debug timeline

#### Scenario: Silent dry run

@e2e tests/e2e/version-management.spec.ts

- WHEN the install endpoint is called with `dryRun=1` and no `debug`
- THEN the dry-run path MUST execute and report its outcome without the debug timeline

#### Scenario: Legacy behavior preserved

@e2e exclude the debug-implies-dryRun resolution matrix is unit-tested; a real install via the web API 503s the test image.

- WHEN the endpoint is called with `debug=1` and no `dryRun` parameter
- THEN the dry-run path MUST execute (legacy), and the response MAY carry a deprecation notice

### Requirement: Pre-flight Environment Checks [MVP]

The system MUST detect environment conditions that prevent a successful install before downloading a release, and surface them both proactively (on the app card) and as a fail-fast guard at install time. The authoritative functional check MUST be whether the parent directory of the app's install destination is writable by the web-server user (`is_writable(dirname($destination))`, because `rename()` of the existing folder requires write permission on the parent). Dev-checkout heuristics — presence of a `.git` directory in the app folder, and/or the app-folder owner differing from the web-server uid — MAY be used to enrich the human-readable warning but MUST NOT, by themselves, block an install.

#### Scenario: Non-manageable app is flagged on its card

@e2e tests/e2e/version-management.spec.ts

- GIVEN an app folder whose parent directory is not writable by the web-server user (e.g. a bind-mounted dev checkout)
- WHEN the installed-apps list loads
- THEN that app's card data MUST include `manageable: false`
- AND a `warning` reason MUST be present explaining that the folder is not writable and installs will fail
- AND the warning MUST be a translatable string
- AND the warning MUST NOT prevent the card from being displayed

#### Scenario: Writable app reports manageable

@e2e tests/e2e/version-management.spec.ts

- GIVEN an app folder whose parent directory is writable by the web-server user
- WHEN the installed-apps list loads
- THEN that app's card data MUST include `manageable: true`
- AND no blocking `warning` MUST be set for writability

#### Scenario: Install aborts fast on non-writable destination

@e2e exclude the app directory cannot be made non-writable to the web user within an e2e run; the preflight guard is unit-tested in EnvironmentCheckTest.

- GIVEN an app folder whose parent directory is not writable by the web-server user
- WHEN the admin attempts to install a version of that app
- THEN the system MUST abort before downloading any release
- AND the failure payload MUST have category `preflight_permission`
- AND the HTTP status MUST NOT be 500
- AND the `hint` MUST advise fixing folder ownership/permissions (likely a bind-mounted dev checkout)

#### Scenario: Dev-checkout heuristics enrich but do not block

@e2e exclude requires a .git dev checkout of a managed app; the advisory heuristics are unit-tested.

- GIVEN an app folder that is writable but contains a `.git` directory
- WHEN the installed-apps list loads
- THEN the install MUST NOT be blocked by the guard
- AND any `warning` derived from the `.git`/owner heuristic MUST be advisory only

### Requirement: Install Outcome Taxonomy and Finalize-Phase Recovery [MVP]

The install result MUST report one of three outcomes via `installStatus`: `installed` (clean success), `reverted` (a pre-finalize failure occurred and the previous files were restored from backup — fully safe), or `installed-but-broken` (the finalize phase failed, or the previous files could not be cleanly restored, leaving state uncertain). To make `reverted` possible for finalize-phase failures, the backup folder (`.appversion-backup`) MUST be retained until `finalize()` succeeds in BOTH installers; on a finalize-phase throw the system MUST attempt to restore the previous files from the backup. Because Nextcloud database migrations are forward-only, the system MUST NOT claim a clean rollback after a finalize-phase failure; the outcome MUST surface that files were reverted but database state is uncertain and a manual check is advised. The system does NOT handle database migration rollbacks.

#### Scenario: Clean install reports installed

@e2e tests/e2e/version-management.spec.ts

- GIVEN a download, extraction, file-swap, and finalize all succeed
- WHEN the install completes
- THEN `installStatus` MUST be `installed`
- AND the backup folder MUST have been removed only after `finalize()` succeeded

#### Scenario: Pre-finalize failure reports reverted

@e2e tests/e2e/forge.spec.ts

- GIVEN the file copy fails before `finalize()` runs
- WHEN the failure is handled
- THEN the previous app files MUST be restored from the backup
- AND `installStatus` MUST be `reverted`
- AND the message MUST indicate the previous version is intact

#### Scenario: Finalize-phase failure reports installed-but-broken

@e2e exclude requires injecting a fault into the finalize phase, not reachable through the UI/API; the outcome taxonomy is unit-tested.

- GIVEN `finalize()` throws (e.g. a declared migration or repair step fails)
- WHEN the failure is handled
- THEN the system MUST attempt to restore the previous files from the retained backup
- AND `installStatus` MUST be `installed-but-broken`
- AND the failure `category` MUST be `finalize`
- AND the `hint` MUST state that files were reverted but database migrations may have partially applied and cannot be rolled back automatically, advising a manual check

#### Scenario: Finalize failure with failed restore reports indeterminate state

@e2e exclude requires a double fault (finalize + restore both fail); not reachable in e2e, unit-tested.

- GIVEN `finalize()` throws AND restoring the previous files from backup also fails
- WHEN the failure is handled
- THEN `installStatus` MUST be `installed-but-broken`
- AND the `hint` MUST state that the install is in an indeterminate state requiring manual intervention

#### Scenario: App that swallows its own init error is out of scope

@e2e exclude explicitly out of scope per the spec — the app cannot detect an installed app that hides its own init failure.

- GIVEN an installed app catches and logs its own initialization/boot exception (no exception propagates to Versioniq)
- WHEN the install otherwise completes the file swap and finalize successfully
- THEN Versioniq MUST report `installed`
- AND Versioniq is NOT required to detect the app's internal init failure (this is explicitly out of scope and undetectable)

### Requirement: Admin Settings Placement [MVP]

The Versioniq UI MUST be surfaced exclusively as a section in the Nextcloud Administration settings panel, registered via `appinfo/info.xml` `<settings>` using an `OCP\Settings\IIconSection` (the sidebar section) and an `OCP\Settings\ISettings` (the form body). The previous top-level `<navigations>` entry MUST be removed, and the standalone page route (`PageController` / `FrontpageRoute`) MUST be removed so the only entry point is the admin settings section. The section name and any user-facing strings MUST be translatable.

#### Scenario: Admin sees Versioniq in Administration settings

@e2e tests/e2e/version-management.spec.ts

- GIVEN a Nextcloud administrator opens Settings
- WHEN the administrator navigates to the Administration area
- THEN an "Versioniq" entry MUST appear in the Administration sidebar
- AND the entry MUST display the translated section name and the app icon

#### Scenario: Top-level navigation entry is absent

@e2e tests/e2e/version-management.spec.ts

- GIVEN any logged-in Nextcloud user
- WHEN they view the top-level application navigation menu
- THEN no "Versioniq" entry MUST appear in the menu

#### Scenario: No standalone page route remains

@e2e tests/e2e/version-management.spec.ts

- GIVEN the move to admin settings is complete
- WHEN any user requests the former front-page route of the app
- THEN no `FrontpageRoute`/page controller MUST serve the UI
- AND the UI MUST be reachable only through Settings → Administration → Versioniq

### Requirement: Settings Form Renders the Existing SPA [MVP]

The admin settings form MUST render the existing Versioniq Vue SPA inside the settings panel, reusing the existing template and JS/CSS bundle without modification. The SPA MUST be fully functional (app list, version picker, install) within the embedded settings context.

#### Scenario: SPA loads and functions inside the settings panel

@e2e tests/e2e/shell.spec.ts

- GIVEN an administrator opens Settings → Administration → Versioniq
- WHEN the settings panel loads
- THEN the Versioniq Vue SPA MUST render within the settings page
- AND its JavaScript and CSS bundles MUST be loaded (via `Util::addScript` / `Util::addStyle`)
- AND the installed-apps list MUST populate and version management MUST be usable without leaving the settings panel

### Requirement: Tabbed admin UI in the settings context [MVP]

The admin UI MUST present a tab/section switcher with at least the sections Apps, Sources, Tokens, and Trusted sources, with the existing apps → versions → install view as the default tab. The UI MUST render cleanly inside the Nextcloud admin Settings panel (Settings → Administration), without the full app-shell chrome.

#### Scenario: Tabs rendered in admin settings

@e2e tests/e2e/shell.spec.ts

- **GIVEN** an admin opens Settings → Administration → Versioniq
- **WHEN** the panel loads
- **THEN** the UI MUST display a tab/section switcher with Apps, Sources, Tokens, and Trusted sources
- **AND** the Apps tab (the existing apps → versions → install view) MUST be selected by default

#### Scenario: Switching tabs

@e2e tests/e2e/shell.spec.ts

- **GIVEN** the admin is on the Apps tab
- **WHEN** the admin selects the Sources, Tokens, or Trusted sources tab
- **THEN** the corresponding panel (`SourcesPanel`, `TokensPanel`, or `TrustedSourcesPanel`) MUST be shown
- **AND** the previously shown panel MUST be hidden

#### Scenario: Settings-context shell adaptation

@e2e tests/e2e/shell.spec.ts

- **GIVEN** the app is mounted inside the admin Settings section (per `move-to-admin-settings`)
- **WHEN** the UI renders
- **THEN** the UI MUST NOT render the full app-shell chrome (`NcContent`/`NcAppContent` navigation rail)
- **AND** MUST use a settings-appropriate container so the SPA fits within the settings panel

#### Scenario: Existing apps/versions flow preserved

@e2e tests/e2e/versions.spec.ts

- **GIVEN** an admin is on the default Apps tab
- **WHEN** the admin selects an app and views its versions
- **THEN** the existing version list and install flow MUST behave as before this change

#### Scenario: Non-admin user is blocked

@e2e tests/e2e/shell.spec.ts

- **GIVEN** a non-admin user reaches the panel
- **WHEN** it loads
- **THEN** the UI MUST show a "Forbidden" state
- **AND** the write endpoints MUST return HTTP 403

## User Stories

1. As a Nextcloud admin, I want to roll back an app to a previous version so that I can recover from a broken update.
2. As a Nextcloud admin, I want to see all available versions of an installed app so that I can choose which version to install.
3. As a Nextcloud admin, I want to test a newer version of an app before it's auto-updated so that I can verify compatibility.
4. As a developer, I want to install a specific version of an app so that I can reproduce a bug reported on that version.
5. As a sysadmin, I want password confirmation before version changes so that unauthorized users can't modify app versions.

## Acceptance Criteria

- [ ] App list shows all installed apps with current version
- [ ] Non-admin users see "Forbidden" and cannot access any API
- [ ] Version list shows all releases from app store with compatibility info
- [ ] Currently installed version is highlighted in the list
- [ ] Installing an older version works (rollback)
- [ ] Installing a newer version works (upgrade)
- [ ] Password confirmation is required before install
- [ ] Graceful error handling when app store is unreachable
- [ ] Graceful error handling when download/install fails
- [ ] Debug mode returns detailed logs
- [ ] Versioniq itself is excluded from the manageable apps list

## Notes

- The app uses the Nextcloud App Store API at `https://apps.nextcloud.com/api/v1/apps/{appId}/releases`
- Version compatibility is determined by comparing the release's `minNextcloudVersion`/`maxNextcloudVersion` against `OCP\ServerVersion`
- The `SelectedReleaseInstallerService` handles the actual download + extraction, monkey-patching the Nextcloud installer to target a specific version
- This app does NOT handle database migration rollbacks — rolling back to an older version may leave newer DB migrations in place
