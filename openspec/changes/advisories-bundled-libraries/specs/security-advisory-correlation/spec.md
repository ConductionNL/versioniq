# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [advisories-bundled-libraries](../../)

## ADDED Requirements

### Requirement: Libraries bundled inside installed apps are checked against OSV

When an admin switched on the library check (off by default, with the setting stating that library names and versions are sent to OSV), a daily background job MUST read `vendor/composer/installed.json` of every enabled app, collect the PHP libraries and versions it lists except development-only packages and branch versions, and ask the OSV API about each distinct library and version in the `Packagist` ecosystem. For every library with a known vulnerability the system MUST store the advisory id, CVE ids, severity (from the record, else `unknown`), a summary, the first fixed version and a link, with the time of the check. The Advisories tab MUST show per app how many libraries were checked and each finding with its fixed version and the next step for the admin, MUST say when an app has no library manifest, and MUST say that JavaScript built into apps is not checked. An app card with findings MUST carry a badge linking to them. Library findings MUST NOT change the app's advisory state, the compliance status or any automatic install. Only libraries bundled in installed apps MUST be checked; nothing outside the instance.

#### Scenario: A vulnerable library inside an app is shown

@e2e tests/e2e/bundled-libraries.spec.ts

- **GIVEN** the library check is on, the fixture app bundles `vendor/library` 2.4.3, and OSV reports an advisory for it fixed in 2.4.5
- **WHEN** the library job runs and the admin opens the Advisories tab
- **THEN** the fixture app's "Bundled libraries" section MUST list `vendor/library` 2.4.3 with the advisory id, its severity and "Fixed in 2.4.5"
- **AND** the fixture app card MUST show a library advisory badge
- **AND** the app's own advisory state MUST NOT change

#### Scenario: An app without a manifest is named, not skipped silently

@e2e exclude covered by BundledLibraryScannerTest and BundledLibrariesSection.spec.ts.

- **GIVEN** the library check is on and `calendar` ships no `vendor/composer/installed.json`
- **WHEN** the admin opens the Advisories tab after the job ran
- **THEN** the `calendar` block MUST say that no library manifest was found, so its libraries were not checked

#### Scenario: Nothing is sent while the check is off

@e2e exclude covered by LibraryAdvisoryJobTest.

- **GIVEN** an admin never switched on the library check
- **WHEN** the daily library job runs
- **THEN** no request MUST be sent to OSV
