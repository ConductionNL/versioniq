---
status: implemented
---

# CLI Commands Specification

**Status**: implemented
**Standards**: Symfony Console (OC\Core\Command\Base), occ command registration via info.xml
**Feature tier**: MVP

## Purpose

Expose Versioniq's version listing and version-specific install through `occ`, so provisioning scripts, CI pipelines, and Docker image builds can reproduce exact app versions — the capability core `occ app:install` lacks and declined to add (nextcloud/server#36940, PR #40857 unmerged). Commands are thin adapters over `InstallerService`; every integrity check, source binding rule, and failure classification of the HTTP path applies identically.

## Requirements

### Requirement: List versions from the CLI [MVP]

`occ versioniq:versions <appId>` MUST print the available versions of the app from its bound source (or `--source=<sourceId>` override), including installed version, a marker of where each version stands relative to the installed one, whether each version runs on this server (`yes`, `no`, or `unknown` when the source does not say), and source id. `--json` MUST emit the same data as machine-readable JSON. Errors (unknown app, unreachable source) MUST exit non-zero with the classified message on stderr.

#### Scenario: Human listing

@e2e tests/e2e/cli.spec.ts

- GIVEN `openregister` is installed and bound to the App Store
- WHEN `occ versioniq:versions openregister` runs
- THEN it MUST print the installed version and the available versions with compatibility markers
- AND exit code MUST be 0

#### Scenario: Versions that need a different server are marked

@e2e exclude needs an App Store release whose platform range excludes the test server; covered by tests/unit/Command/ListVersionsTest.php and tests/unit/Service/Source/AppStoreSourceTest.php.

- GIVEN an App Store app whose newest release declares `platformVersionSpec` `>=29.0.0 <=30` and the server runs 28.0.0
- WHEN `occ versioniq:versions <appId>` runs
- THEN the column comparing with the installed version MUST be headed "Relative to installed"
- AND that release MUST show `no` under "Runs on this server"
- AND a release whose source states no platform range MUST show `unknown`

#### Scenario: JSON listing

@e2e tests/e2e/cli.spec.ts

- WHEN `occ versioniq:versions openregister --json` runs
- THEN stdout MUST be valid JSON containing `installedVersion`, `availableVersions`, `sourceId`

#### Scenario: Unknown app

@e2e tests/e2e/cli.spec.ts

- WHEN `occ versioniq:versions nope` runs
- THEN the exit code MUST be non-zero and stderr MUST name the problem

### Requirement: Install a specific version from the CLI [MVP]

`occ versioniq:install <appId> <version>` MUST install the requested version through `InstallerService::installAppVersion` — same source resolution, allowlist, integrity verification, backup/restore, maintenance-mode, and finalize behavior as the HTTP path. `--source=` MUST act as the one-off source override; `--dry-run` MUST run the existing dry-run path without swapping files; `--json` MUST emit the structured outcome. A downgrade (target lower than installed) MUST be refused with a distinct exit code unless `--allow-downgrade` is passed. Exit codes MUST map the failure-category taxonomy: 0 success/dry-run-ok, and documented distinct non-zero codes for at least `preflight_permission`, `download`, integrity failures (`checksum_mismatch`/`appid_mismatch`/`version_mismatch`), `incompatible`, `finalize`, downgrade-refused, and unknown.

#### Scenario: Reproducible pinned install

@e2e tests/e2e/cli.spec.ts

- GIVEN a provisioning script for a fresh instance
- WHEN `occ versioniq:install openregister 2.3.0` runs and the source delivers a valid signed release
- THEN version 2.3.0 MUST be installed and the exit code MUST be 0

#### Scenario: Downgrade requires the flag

@e2e tests/e2e/cli.spec.ts

- GIVEN `openregister` installed at 2.5.0
- WHEN `occ versioniq:install openregister 2.3.0` runs without `--allow-downgrade`
- THEN no install MUST happen and the exit code MUST be the documented downgrade-refused code
- AND rerunning with `--allow-downgrade` MUST proceed

#### Scenario: Dry run leaves the instance untouched

@e2e tests/e2e/cli.spec.ts

- WHEN `occ versioniq:install openregister 2.3.0 --dry-run --json` runs
- THEN stdout MUST report the dry-run outcome (`updateType`, checks passed)
- AND the installed version MUST remain unchanged

#### Scenario: Integrity failure exits distinctly

@e2e tests/e2e/cli.spec.ts

- GIVEN the downloaded artifact fails its checksum
- WHEN the install command runs
- THEN the exit code MUST be the documented integrity code and the app MUST remain at its prior version (restore guarantee)

### Requirement: CLI trust context [MVP]

Commands MUST run without password confirmation (CLI executes as the server user, matching core `occ app:install` semantics) and MUST be registered via `info.xml` so they exist wherever the app is enabled. The command MUST refuse to run when the app being managed is Versioniq itself or a core/always-enabled app, mirroring the API guard.

#### Scenario: Self-management refused

@e2e tests/e2e/cli.spec.ts

- WHEN `occ versioniq:install versioniq 1.0.0` runs
- THEN it MUST refuse with a non-zero exit code

### Requirement: List pending updates from the CLI

`occ versioniq:updates` MUST print, for every app in the stored availability snapshot, the installed version, the newest version this server can run, the release lines behind and whether the app is pinned. `--json` MUST print the same data and the snapshot time as JSON. `--refresh` MUST run the availability sweep and store it before printing. `--outside-policy` MUST limit the output to apps past the admin's limit. The command MUST exit 1 when no snapshot exists and `--refresh` was not given.

#### Scenario: A script reads pending updates as JSON

- **GIVEN** the last sweep recorded `openregister` 2.3.0 with 2.4.1 available
- **WHEN** an admin runs `occ versioniq:updates --json`
- **THEN** stdout MUST be valid JSON with `checkedAt` and an `updates` entry for `openregister` naming 2.3.0 and 2.4.1
- **AND** the exit code MUST be 0

#### Scenario: Never checked is not reported as nothing to do

- **GIVEN** no availability sweep has completed
- **WHEN** an admin runs `occ versioniq:updates`
- **THEN** the command MUST say that no check has run and exit 1

## Implementation Notes

- `lib/Command/ListVersions.php` and `lib/Command/InstallVersion.php`, registered via `<commands>` in `appinfo/info.xml`; both delegate to `InstallerService` (no duplicated logic).
- Documented exit-code map: `0` ok · `1` unknown/unclassified · `2` unknown app / bad arguments (includes the self/core-app guard) · `3` downgrade refused · `4` preflight_permission · `5` download · `6` integrity (`checksum_mismatch`/`appid_mismatch`/`version_mismatch`/`sha_mismatch`) · `7` incompatible · `8` finalize (installStatus — `reverted` vs `installed-but-broken` — printed explicitly) · `9` untrusted source.
- `InstallerService::isManageableApp()` is the single shared self/core-app predicate, reused by `getAppVersions()`, `installAppVersion()`, and `InstallVersion`'s CLI pre-check (no duplicated guard logic).
- The MODIFIED "Debug Mode" requirement in `version-management` (dry-run decoupled from `debug`) is the API-side counterpart this capability depends on — see that spec's "Debug Mode" requirement.
- `lib/Command/ListUpdates.php` (`versioniq:updates`) reads the snapshot `AvailabilityResultStore` holds; `--refresh` runs `AvailabilityService::sweep()` first. Exit `1` means no snapshot exists, never "nothing behind" (inventory-pending-updates, archived 2026-09-29).
