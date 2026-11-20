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
- AND after a live install of an app Nextcloud has not enabled, the result MUST offer to enable it

#### Scenario: One-click enable through Nextcloud's own endpoint

@e2e exclude enabling needs a disabled app and the admin's password typed into a dialog on the test instance; covered by src/utils/enableApp.spec.ts and src/dialogs/EnableAppDialog.spec.ts.

- GIVEN `calendar` is installed and disabled, or was just installed and Nextcloud has not enabled it
- WHEN the admin clicks Enable on its card or in the install result, and enters their password
- THEN the page MUST call Nextcloud's own `POST /ocs/v2.php/cloud/apps/calendar` (provisioning API, admin-only) with the password as the Basic credentials its strict password confirmation requires
- AND on success the app list MUST reload with `calendar` enabled; on failure the dialog MUST stay open and show Nextcloud's message

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

The system MUST query the **bound source** for an app to retrieve all available releases. If no source is bound, the App Store is queried by default. Versions MUST be filtered by compatibility with the current Nextcloud version and update channel. Each App Store version entry MUST carry `serverCompatible`: true or false from the release's `platformVersionSpec` against the running server, or null when the release states no range. A forge release carries no range in its release metadata, so for a version held in the artifact cache `serverCompatible` MUST be computed from the `<nextcloud min-version max-version>` range in that archive's own `appinfo/info.xml`; an uncached forge release stays null. The web version picker MUST show the value.

#### Scenario: A cached forge release shows server compatibility

@e2e exclude a cached forge release needs a completed forge install on the instance first; covered by tests/unit/Service/Cache/ArtifactCacheTest.php (testPlatformSpecIsReadFromTheCachedArtifactInfoXml), tests/unit/Service/InstallerServiceCacheTest.php (testCachedForgeVersionsGetServerCompatibilityFromTheirInfoXml) and src/components/ServerCompatBadge.spec.ts.

- GIVEN a forge-bound app whose cached 2.3.0 archive declares `min-version="30" max-version="31"` and a server on 31.0.4
- WHEN the version list loads
- THEN 2.3.0 MUST carry `serverCompatible: true` and the picker MUST show "Runs on this server"
- AND a version whose range excludes the server MUST show "Not for this server version", and an uncached forge release MUST show nothing

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

The system MUST allow an admin to install any available version of an app, replacing the currently installed version. This operation MUST require password confirmation for security. Every install failure MUST return a structured payload — independent of the debug toggle — carrying `stage` (the last installer stage reached, e.g. `backup`, `download`, `checksum`, `archive-extracted`, `info-validated`, `finalize`), `category` (one of `preflight_permission | download | checksum_mismatch | extract | appid_mismatch | version_mismatch | incompatible | finalize | unknown`), and a `hint` (a human, actionable, translatable remediation string). The HTTP status MUST reflect the category and MUST NOT be a blanket 500 for classified failures; 500 is reserved for `unknown`/unexpected errors. A version change MUST keep the app's enabled or disabled state: an app that was enabled, for all users or for some groups, MUST be enabled the same way afterwards, and an app that was disabled MUST stay disabled. A first install of an app that was not installed MUST leave it disabled.

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

#### Scenario: A disabled app stays disabled across a version change

@e2e exclude a real UI-triggered install resets opcache and 503s the mod_php test image; the carried flag is covered by the installer unit tests.

- GIVEN OpenRegister is installed at version 2.5.0 and disabled
- WHEN the admin installs version 2.3.0
- THEN OpenRegister MUST be at version 2.3.0
- AND OpenRegister MUST still be disabled

#### Scenario: An app enabled for some groups keeps its groups

@e2e exclude a real UI-triggered install resets opcache and 503s the mod_php test image; the carried flag is covered by the installer unit tests.

- GIVEN an app is enabled only for the group `finance`
- WHEN the admin installs another version of it
- THEN the app MUST be enabled only for the group `finance` afterwards

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

### Requirement: The app card shows shipped apps and the bound source

Each app card MUST mark an app that ships with Nextcloud but is not always enabled
(`isShipped`), and MUST name the source its versions come from (`boundSourceId`, or the App
Store when unbound). The dead `GET /api/admin-check` route is removed: the page is a
non-delegable admin setting and every endpoint already refuses a non-admin.

#### Scenario: Shipped app and source on the card

@e2e tests/e2e/versions.spec.ts

- **GIVEN** a shipped app that is not always enabled, bound to no source
- **WHEN** the Apps tab renders its card
- **THEN** the card MUST show "Shipped with Nextcloud" and "Source: App Store"

### Requirement: The app list and the version list can be filtered

The Apps tab MUST offer a text filter over the installed app list that keeps an app when its
label or its id contains the typed text, case-insensitive, after the core-apps and updates
filters have run. A filter panel MUST let the admin hide core apps. The version list of the
selected app MUST offer its own text filter that keeps a version whose number contains the
typed text, and MUST say "No versions match your filter." when none does. Both filters run in
the browser over lists already loaded; no request is sent. Code: `src/App.vue` (`filteredApps`,
`filteredVersions`, the `app-filter` input and the "Filter versions" input).

#### Scenario: Filtering the app list by name or id

@e2e exclude the filters are pure computed properties over the loaded list; an e2e run would need a fixed set of installed apps the shared CI instance does not guarantee.

- **GIVEN** the Apps tab lists `calendar`, `contacts` and `openregister`
- **WHEN** the admin types `REG` in the app search field
- **THEN** only `openregister` MUST stay in the list
- **AND** choosing "Hide core apps" MUST also drop every app marked as core

#### Scenario: Filtering the version list

@e2e exclude covered by the computed filter in src/App.vue; the version list depends on live App Store data.

- **GIVEN** the selected app lists versions 2.3.0, 2.4.1 and 3.0.0
- **WHEN** the admin types `2.4` in "Filter versions"
- **THEN** only 2.4.1 MUST stay in the list
- **AND** typing `9.9` MUST show "No versions match your filter."

### Requirement: The admin page works with a keyboard and a screen reader

The admin page MUST start with a "Skip to main content" link that is visible on focus and
moves focus to the page content (`#versioniq-main`). The section tabs MUST be a WAI-ARIA
tablist named "Versioniq sections": each tab carries `role="tab"`, `aria-selected`,
`aria-controls` and a roving `tabindex`, and Left, Right, Home and End move between tabs. The
app detail sections MUST use the same tablist pattern. Every text input MUST carry an
accessible name, and every data table MUST mark its column headers with `scope="col"`. Code:
`src/App.vue` (skip link, `onTabKeydown`, the tablists), `src/components/HistoryPanel.vue` and
`src/components/IntegrationsPanel.vue` (table headers).

#### Scenario: Arrow keys move between tabs

@e2e tests/e2e/shell.spec.ts

- **GIVEN** the admin opens the Versioniq settings page and focuses the Apps tab
- **WHEN** they press the Right arrow key
- **THEN** the Advisories tab MUST read `aria-selected="true"`
- **AND** its tab panel MUST be shown

#### Scenario: The skip link reaches the content

@e2e exclude the skip link is a plain anchor to #versioniq-main; asserted by reading the template, no browser behaviour of our own to test.

- **GIVEN** a keyboard user lands on the settings page
- **WHEN** they press Tab once and then Enter
- **THEN** the "Skip to main content" link MUST have been visible
- **AND** focus MUST move to the page content

### Requirement: The pre-flight check refuses an install that does not fit on disk

Before downloading, the pre-flight check MUST measure the free space in the apps folder and in the temp folder. It MUST require at least three times the package size in the apps folder and two times in the temp folder, assuming 50 MB when the size is unknown. A refusal MUST be classified `preflight_space`, MUST NOT be HTTP 500, and its hint MUST name the free and the needed space. The install progress MUST show the free space that was found.

#### Scenario: Not enough space

- **GIVEN** the apps folder has 40 MB free and the package is 20 MB
- **WHEN** an admin installs the version
- **THEN** the install MUST be refused before download with category `preflight_space` and HTTP 409
- **AND** the hint MUST say 40 MB is free and 60 MB is needed

#### Scenario: The check shows what it found

- **GIVEN** enough free space
- **WHEN** an admin installs a version
- **THEN** the progress MUST show "Environment checked" with the free space and that the app folder is writable

### Requirement: Every write action asks for the admin password

Every route that changes state MUST ask the admin to confirm their password first. This covers binding and clearing a source, trusting and untrusting a source, installing a version, pinning and unpinning, retrying an attempt, setting and clearing an update policy, the update and advisory settings, the instance settings, the Forgejo host, tokens and the cache. Read routes MUST NOT ask for it. occ commands run as the server user and MUST NOT ask for it, as "CLI trust context" says.

#### Scenario: An install waits for the password

@e2e exclude the password dialog belongs to Nextcloud core; the route attribute is covered by tests/unit/Controller/PasswordConfirmationTest.php.

- **GIVEN** an admin who has not confirmed their password in this session
- **WHEN** the admin installs a version, pins an app or saves a token
- **THEN** Nextcloud MUST ask for the password before the request runs
- **AND** without the password the request MUST be refused and nothing MUST change

#### Scenario: Reading needs no password

@e2e exclude covered by tests/unit/Controller/PasswordConfirmationTest.php.

- **WHEN** the admin opens the Apps tab or lists the versions of an app
- **THEN** no password MUST be asked

### Requirement: Core apps and Versioniq itself are never changed

Versioniq MUST refuse to list versions of, install or update a core app that Nextcloud always enables, and Versioniq itself. The refusal MUST hold on every path: the API, the occ commands and the automatic update job. The API MUST answer HTTP 403 with a message that names the reason. Core apps stay visible in the app list, marked as core, as "List Installed Apps" says.

#### Scenario: An install of a core app is refused

@e2e exclude a core app cannot be offered from the page; covered by tests/unit/Service/InstallerServiceTest.php.

- **GIVEN** `files` is a core app that Nextcloud always enables
- **WHEN** a request installs a version of `files`
- **THEN** the answer MUST be HTTP 403 with "This core app cannot be installed or updated from Versioniq."
- **AND** no file MUST change

#### Scenario: Versioniq does not replace itself

@e2e exclude Versioniq is left out of its own app list; covered by tests/unit/Service/InstallerServiceTest.php.

- **WHEN** an admin runs `occ versioniq:install versioniq 1.0.0`
- **THEN** the command MUST exit with code 2 and change nothing

### Requirement: The version view shows an About card with publisher, licence and links

When an admin opens an app's versions, the page MUST show an "About <app>" card beside the version list, as drawn on the VqAppVersies board. The card MUST show the publisher (the author in the app's `appinfo/info.xml`), the licence, the installed version with its enabled or disabled state, and the app's description. It MUST link to the website, the source repository and the issue tracker the app declares, each opening in a new tab. A link the app does not declare MUST be left out, not shown empty. The list payload of `GET /api/apps` MUST carry `author`, `licence` and `links` (`website`, `repository`, `bugs`) for every installed app.

#### Scenario: An admin reads who publishes an app and under which licence

@e2e tests/e2e/version-management.spec.ts

- **GIVEN** Dossiq 0.4.48 is installed and enabled, and its `info.xml` names author "Conduction", licence `EUPL-1.2`, a website, a repository and a bug tracker
- **WHEN** the admin opens the versions of Dossiq
- **THEN** the About card MUST show "Conduction", "EUPL-1.2" and "0.4.48, enabled"
- **AND** it MUST link to the website, the source and the issue tracker

#### Scenario: An app without a bug tracker

- **GIVEN** an installed app whose `info.xml` declares a website but no `bugs` element
- **WHEN** the admin opens its versions
- **THEN** the card MUST show the website link
- **AND** it MUST NOT show a "Report a problem" link

#### Scenario: The list payload carries the metadata

- **WHEN** an admin calls `GET /api/apps`
- **THEN** every installed app MUST carry `author`, `licence` and `links`
- **AND** a value the app does not declare MUST be `null`

### Requirement: An admin updates one app or all apps in one action

A card whose app has a newer version this server can run MUST offer "Update to {version}", which MUST show the move and the release notes in between and install that version through the normal install endpoint after one confirmation. The Apps tab MUST offer "Update all" when any app has such a version: it MUST list those apps, leave pinned apps unticked with the reason, ask for the password at most once for the run, install the ticked apps one after the other through the same endpoint, show each outcome as it lands, and continue after a failure. No action here MAY bypass a guard of the install endpoint.

#### Scenario: An admin updates one app from its card

- **GIVEN** `openregister` 2.3.0 is installed and the last sweep recorded 2.4.1 as the newest version this server can run
- **WHEN** an admin clicks "Update to 2.4.1" on the card and confirms
- **THEN** `openregister` 2.4.1 MUST be installed through the install endpoint
- **AND** the History tab MUST show the install row

#### Scenario: An admin updates all apps and one fails

- **GIVEN** `openregister`, `calendar` and `hermiq` have newer versions, `hermiq` is pinned, and the `calendar` download fails its checksum
- **WHEN** an admin clicks "Update all", keeps the default ticks, and confirms their password once
- **THEN** `hermiq` MUST be listed unticked as pinned and MUST NOT be installed
- **AND** `openregister` MUST read updated and `calendar` MUST read failed with its category and hint
- **AND** the admin MUST NOT be asked for the password a second time within the platform's confirmation window

### Requirement: An app left disabled by an update is reported and, when it can run, re-enabled

When an app that was enabled is found disabled while its own version or the server version changed since Versioniq last saw it, the system MUST treat it as disabled by an update. When the installed version declares support for the running server and the admin switched on automatic re-enabling, the system MUST enable it and record a `reactivate` audit entry with the outcome. Otherwise it MUST notify every admin, naming the app and the change, and saying whether the installed version runs on this server, with the newest version that does when one is known. The app card MUST say "Disabled by the update to {version}" and MUST offer Enable when the version runs. A disable without a version change of the app or the server MUST be recorded as `external_disable` with its actor and MUST NOT be re-enabled. Automatic re-enabling MUST be off by default, and switching it MUST require password confirmation and be recorded in the audit trail.

#### Scenario: A server upgrade leaves an app off, and it runs

- **GIVEN** `forms` 4.3.0 was enabled and declares support for Nextcloud 32 to 34, the server was upgraded from 33 to 34, and `forms` is disabled afterwards
- **WHEN** the hourly check runs with automatic re-enabling off
- **THEN** admins MUST be notified that `forms` was left disabled and runs on this server
- **AND** its card MUST read "Disabled by the update to Nextcloud 34" with Enable

#### Scenario: With the switch on, Versioniq enables it

- **GIVEN** the same situation and automatic re-enabling is on
- **WHEN** the hourly check runs
- **THEN** `forms` MUST be enabled and the audit trail MUST hold a `reactivate` entry with status success

#### Scenario: An app that does not run stays off

- **GIVEN** `hermiq` 1.4.0 declares at most Nextcloud 33, the server moved to 34, and `hermiq` was disabled by the upgrade
- **WHEN** the hourly check runs, with the switch on or off
- **THEN** `hermiq` MUST stay disabled, and the notification MUST say it does not run on Nextcloud 34

#### Scenario: A deliberate disable is left alone

- **GIVEN** admin `bob` disables `calendar` on the Apps page, with no version change
- **WHEN** the hourly check runs with the switch on
- **THEN** `calendar` MUST stay disabled, and the History tab MUST show an `external_disable` entry by bob

### Requirement: Versions say whether they run on this server's PHP

Each listed version MUST carry, next to `serverCompatible`, whether it runs on the PHP version this server runs (`phpCompatible`: true, false, or null when the source gives no range). The App Store release's `phpVersionSpec` and a forge release's `info.xml` PHP range MUST both feed it. The version picker MUST show a version that does not run on this PHP as such, before the admin installs it.

#### Scenario: An admin sees a PHP mismatch before installing

- **GIVEN** the server runs PHP 8.1 and `openregister` 3.0.0 declares PHP 8.2 or higher
- **WHEN** an admin opens the `openregister` version list
- **THEN** 3.0.0 MUST show that it does not run on this PHP version

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
