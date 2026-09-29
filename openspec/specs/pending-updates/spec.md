---
status: implemented
---

# Pending Updates Specification

**Status**: implemented
**Scope**: versioniq
**OpenSpec changes**: [inventory-pending-updates](../../changes/archive/2026-09-29-inventory-pending-updates/)

## Purpose

An admin sees on one screen which apps run which version, which have a newer one, and which fell behind the agreed limit.

## Requirements

### Requirement: The installed version and pending updates are swept into a snapshot

The system MUST work out, in a background job and never in a page request, for every installed app it may manage: the installed version, the newest plain `major.minor.patch` version its bound source lists, the newest such version this server can run, the number of `major.minor` release lines between the installed version and that one, whether the app is pinned, and the source error when the source did not answer. The system MUST store the result with the time the sweep completed, and MUST keep the previous snapshot when a new one cannot be stored.

#### Scenario: A newer compatible version is recorded

- **GIVEN** `openregister` 2.3.0 is installed and bound to the App Store, which lists 2.3.4, 2.4.1 and 3.0.0, and 3.0.0 does not run on this server
- **WHEN** the availability sweep runs
- **THEN** the snapshot MUST record `newestVersion` 3.0.0, `newestCompatibleVersion` 2.4.1 and `linesBehind` 1 for `openregister`

#### Scenario: An unreachable source is not read as up to date

- **GIVEN** `hermiq` is bound to a GitHub repository and GitHub refuses the request
- **WHEN** the availability sweep runs
- **THEN** the snapshot MUST carry the source error for `hermiq` and no newest version

### Requirement: Every app card shows its installed version and whether an update is available

The Apps tab MUST show the installed version on every card of an installed app. When the stored snapshot names a newer version this server can run, the card MUST show an "Update available" badge with that version. A pinned app MUST keep the badge and MUST say that the pin holds the update. The page MUST say when updates were last checked, and MUST say so plainly when no sweep has completed.

#### Scenario: An admin sees which apps are behind

- **GIVEN** the last sweep recorded `openregister` 2.3.0 with 2.4.1 available, and `calendar` up to date
- **WHEN** an admin opens the Apps tab
- **THEN** the `openregister` card MUST read "Installed 2.3.0" and show "Update available: 2.4.1"
- **AND** the `calendar` card MUST show its installed version and no update badge

#### Scenario: A pinned app still shows its update

- **GIVEN** `openregister` is pinned to 2.3.0 and the last sweep recorded 2.4.1 available
- **WHEN** an admin opens the Apps tab
- **THEN** the card MUST show "Update available: 2.4.1, held by the pin"

#### Scenario: No sweep has run yet

- **GIVEN** a fresh install where the availability sweep has not completed
- **WHEN** an admin opens the Apps tab
- **THEN** the page MUST say that updates have not been checked yet, and MUST NOT show any card as up to date

### Requirement: An admin sets how far an app may fall behind

An admin MUST be able to set, on the Settings tab, how many release lines an app may fall behind, from 0 to 10, or leave it empty to switch the check off. The Apps tab MUST flag every app whose recorded `linesBehind` is greater than the limit, and MUST offer a filter for apps with an update and for apps outside the limit. `GET /api/updates` MUST be admin-only and MUST return the snapshot, its time and the limit.

#### Scenario: An app outside an N-1 policy is flagged

- **GIVEN** the limit is 1 and the last sweep recorded `openregister` 2 release lines behind
- **WHEN** an admin opens the Apps tab and picks "Outside the update policy" in the filter
- **THEN** only `openregister` and other apps past the limit MUST be listed, each flagged "Outside the update policy: 2 releases behind"

#### Scenario: A non-admin cannot read the snapshot

- **GIVEN** a user who is not an admin
- **WHEN** they call `GET /ocs/v2.php/apps/versioniq/api/updates`
- **THEN** the response MUST be 403 and carry no app data

## Implementation Notes

- `lib/BackgroundJob/AvailabilityRefreshJob.php` (every 6 hours, 600 s budget) runs `lib/Service/Availability/AvailabilityService.php::sweep()` and saves to `AvailabilityResultStore` (app config `availability.results`, `availability.results.checkedAt`).
- `GET /api/updates` (`ApiController::updates`) is admin-only and reads the snapshot; the lag limit is `update.max_lines_behind` in `InstanceSettings`.
- The Apps tab renders the badge, the policy flag, the filter and the freshness line through `src/utils/pendingUpdates.ts`.
