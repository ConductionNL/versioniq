# upgrade-readiness Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-upgrade-readiness](../../)

## Purpose

Before an admin upgrades Nextcloud or PHP, they see which apps are ready, which need an update first, and which have no release for the target.

## ADDED Requirements

### Requirement: An admin checks every installed app against a target server major and PHP version

The system MUST let an admin check every installed app against a target Nextcloud major and a target PHP version, from the Upgrade readiness tab and from `occ versioniq:readiness`. Each app MUST get one verdict: `shipped` (it follows the server release), `ready` (the installed version supports both targets), `updateFirst` (a newer listed version does, named in the report), `blocked` (no listed version does), or `unknown` (the source gives no range). A check started from the page MUST run in a background job and MUST NOT list versions inside the request. The page MUST show the time and the targets of the last report.

#### Scenario: An admin sees what blocks the next server major

- **GIVEN** the server runs Nextcloud 32 on PHP 8.2, `openregister` 2.3.0 supports up to 32 and 2.5.0 supports 33, and `oldapp` has no release that supports 33
- **WHEN** an admin opens the Upgrade readiness tab, keeps the target 33 and PHP 8.2, clicks Check, and the job completes
- **THEN** the report MUST list `openregister` under "Update first" with 2.5.0
- **AND** it MUST list `oldapp` under "Blocked"

#### Scenario: A PHP upgrade is checked the same way

- **GIVEN** `calendar` 5.0.0 is installed and declares PHP up to 8.3
- **WHEN** an admin runs `occ versioniq:readiness --server=32 --php=8.4 --json`
- **THEN** the JSON MUST give `calendar` a verdict other than `ready`
- **AND** the exit code MUST be 0

#### Scenario: A non-admin cannot start a check

- **GIVEN** a user who is not an admin
- **WHEN** they call `POST /ocs/v2.php/apps/versioniq/api/readiness`
- **THEN** the response MUST be 403 and no job MUST be queued

### Requirement: A forge release's ranges are read without downloading the release

For a forge-bound app, the system MUST read the server and PHP range of a listed release from `appinfo/info.xml` at the release tag on the forge, when neither a stored range nor a cached archive exists, and MUST store the answer so the same release is not read again. A release without a readable `info.xml` MUST be stored as having no range and MUST read unknown, never incompatible.

#### Scenario: An uncached forge release shows whether it runs here

- **GIVEN** `hermiq` is bound to a GitHub repository, release 1.4.0 is not in the artifact cache, and its `appinfo/info.xml` declares Nextcloud 31 to 33
- **WHEN** an admin opens the `hermiq` version list on a Nextcloud 32 server
- **THEN** 1.4.0 MUST show "Runs on this server"
- **AND** opening the list again MUST NOT read the file from GitHub again
