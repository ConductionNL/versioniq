---
status: implemented
---

# Changelog Visibility Specification

**Status**: implemented
**OpenSpec changes**: [releases-notes-reach](../../changes/archive/2026-11-24-releases-notes-reach/)
**Standards**: Nextcloud App Store API v1 (release translations), GitHub REST API v2022-11-28 (release body), Forgejo/Codeberg API v1
**Feature tier**: MVP

## Purpose

Admins choosing a version to install — especially rolling back past intermediate releases — need to read what each release changed. Both bound source kinds already deliver release notes; this capability surfaces them in the version listing and aggregates them across the installed→target range. Rendering is fail-soft and sanitized; changelog availability never gates an install.

## Requirements

### Requirement: Version listings carry release notes [MVP]

`GET /api/app/{appId}/versions` MUST include a nullable `changelog` string per version. `AppStoreSource` MUST map the release's `translations` changelog (requested language, falling back to `en`); `ForgeReleaseSource` MUST map the release `body`. The server MUST truncate each changelog to at most 8 KiB (appending a truncation marker) and MUST return `null` when the source provides none. Changelog extraction failures MUST NOT fail or delay the version listing.

#### Scenario: Forge release body is returned

@e2e tests/e2e/install-effects.spec.ts

- GIVEN `openregister` is bound to `github:ConductionNL/openregister` and release 2.3.0 has a body "Fixes LDAP sync"
- WHEN the admin fetches `/api/app/openregister/versions`
- THEN the 2.3.0 entry MUST contain `changelog: "Fixes LDAP sync"`

#### Scenario: Missing notes are null, listing still works

@e2e exclude the null-changelog mapping is unit-tested in AppStoreSource/ForgeReleaseSource.

- GIVEN a release without notes
- WHEN versions are fetched
- THEN that entry's `changelog` MUST be `null`
- AND the version listing MUST succeed with all other fields intact

#### Scenario: Oversized changelog is truncated server-side

@e2e exclude the 8 KiB truncation is unit-tested.

- GIVEN a release whose notes exceed 8 KiB
- WHEN versions are fetched
- THEN the returned `changelog` MUST be at most 8 KiB plus a truncation marker

---

### Requirement: Per-version changelog display [MVP]

The version picker MUST let the admin expand any version row to read its release notes, rendered as sanitized text/markdown (raw HTML MUST NOT be injected into the DOM). Rows without notes MUST show a localized "No release notes provided".

#### Scenario: Expand a version row

@e2e tests/e2e/versions.spec.ts

- GIVEN the version list for `openregister` is displayed
- WHEN the admin expands the 2.3.0 row
- THEN its release notes MUST be shown, sanitized
- AND a row without notes MUST show "No release notes provided"

#### Scenario: Markdown is not an XSS vector

@e2e tests/e2e/versions.spec.ts

- GIVEN a release body containing `<script>alert(1)</script>`
- WHEN the row is expanded
- THEN the script MUST be rendered inert (escaped or stripped), never executed

---

### Requirement: Aggregate range changelog on target selection [MVP]

When the admin selects a target version different from the installed one, the UI MUST show an aggregate panel listing, per intermediate release in the range (installed, target], each version label and its notes (or the no-notes placeholder), ordered from installed towards the target — for downgrades this means the releases being rolled back, newest first. The aggregate MUST reuse the already-fetched listing (no extra requests per version).

#### Scenario: Upgrade range aggregation

@e2e exclude the installed->target range aggregation is covered by ChangelogRangePanel vitest.

- GIVEN `openregister` installed at 2.3.0 and target 2.5.0 selected, with releases 2.4.0 and 2.5.0 in between
- WHEN the aggregate panel renders
- THEN it MUST list 2.4.0 and 2.5.0 each with their notes

#### Scenario: Downgrade shows what is being undone

@e2e exclude the downgrade range ordering is covered by ChangelogRangePanel vitest.

- GIVEN installed 2.5.0 and target 2.3.0
- WHEN the aggregate panel renders
- THEN it MUST list 2.5.0 and 2.4.0 (the releases rolled back), newest first
- AND the existing downgrade confirmation flow MUST remain unchanged

### Requirement: A version its publisher marks as breaking is flagged

Every version entry MUST carry `breaking`, true when its full release notes, read before truncation, contain a heading "Breaking change" or "Breaking changes" at any level, with or without a leading warning sign, or a line starting with `BREAKING CHANGE:` or `BREAKING-CHANGE:`. The picker MUST show a "Breaking" badge on such a version, separate from the major version badge. When any version between the installed and the chosen version is breaking, the range summary and the downgrade dialog MUST name those versions.

#### Scenario: A breaking minor release is named before the upgrade

- **GIVEN** `openregister` 2.3.0 is installed and the notes of 2.4.0 carry a "⚠ BREAKING CHANGES" heading
- **WHEN** an admin selects 2.5.0
- **THEN** 2.4.0 MUST show the "Breaking" badge
- **AND** the range summary MUST say "Includes releases marked as breaking: 2.4.0."

#### Scenario: A marker at the end of long notes still counts

- **GIVEN** a release whose notes run past 8 KiB and end with a `BREAKING CHANGE:` line
- **WHEN** the version list loads
- **THEN** that entry MUST carry `breaking: true` although its returned changelog is truncated

### Requirement: Known issues of a version are shown before and after installing it

When a version's release notes contain a section headed "Known issues" or "Known problems", the picker MUST show that section on its own, above the rest of the notes, as plain text. An admin MUST be able to add a known issue to a version, with a text, a workaround and an optional https link, and to remove it, through admin-only, password-confirmed endpoints that are recorded in the audit trail. Admin notes MUST show on that version in the picker, and the app card MUST say "Known issue in the installed version" while an app runs a version with an admin note.

#### Scenario: A publisher adds a known issue after release

@e2e tests/e2e/versions.spec.ts

- **GIVEN** `hermiq` 1.4.0 was released on GitHub, and a week later its release body gained a "Known issues" section: "LDAP sync stops after 1000 users; set the page size to 500"
- **WHEN** an admin opens the version list of `hermiq`
- **THEN** 1.4.0 MUST show that section under "Known issues", above its release notes

#### Scenario: An admin records a problem found in testing

- **GIVEN** `openregister` 2.4.1 is installed
- **WHEN** admin `alice` adds the known issue "Export to CSV times out on large registers" with the workaround "Export per schema" to 2.4.1 and confirms her password
- **THEN** the 2.4.1 entry MUST show the issue and its workaround
- **AND** the `openregister` card MUST say "Known issue in the installed version"
- **AND** the audit trail MUST hold a `settings` row by alice naming `openregister` 2.4.1

### Requirement: Users hear what changed after an update through Versioniq

After a successful real install that changes an app's version, the system MUST dispatch the platform's `AppUpdateEvent` for that app, after the app's pin state is settled, so the platform notifies the app's users as it does for its own updates. A dry run, a reinstall of the same version and a failed install MUST NOT dispatch it. An admin MUST be able to switch this off on the Settings tab; it MUST be on by default.

#### Scenario: A user is told about an update the admin made in Versioniq

- **GIVEN** `calendar` is enabled for user `bob`, and an admin updates `calendar` from 5.0.0 to 5.1.0 through Versioniq
- **WHEN** the platform processes the update event
- **THEN** `bob` MUST receive Nextcloud's app updated notification for `calendar`

#### Scenario: A re-pin does not read as drift

- **GIVEN** `openregister` is pinned to 2.3.0 and an admin installs 2.4.0 with override re-pin
- **WHEN** the install completes and the event is dispatched
- **THEN** the pin MUST read 2.4.0 with no drift, and no drift notification MUST be sent

### Requirement: An admin translates release notes into their language

Each version entry MUST say which language its release notes are in, or that the language is unknown. When the notes are not in the admin's language and the instance has a translation provider, the release notes disclosure MUST offer "Translate to {language}". The translated text MUST be labelled as a machine translation, MUST be stored so the same notes are translated once, and the original MUST stay reachable. Without a provider the button MUST NOT show.

#### Scenario: A Dutch admin reads English notes in Dutch

- **GIVEN** the admin's language is Dutch, the instance has a translation provider, and `openregister` 2.4.1 has English release notes only
- **WHEN** the admin opens the 2.4.1 release notes and clicks "Translate to Dutch"
- **THEN** the notes MUST show in Dutch, marked "Machine translation"
- **AND** opening them again MUST NOT call the translation provider again
