---
status: implemented
---

# Migration Safety Specification

**Status**: implemented
**Standards**: OCP\App\IAppManager, OCP\IAppConfig, Nextcloud migration framework (OCP\Migration\IMigrationStep)
**Feature tier**: MVP

## Purpose

Nextcloud schema migrations are forward-only; downgrading an app's files cannot undo its schema changes. This capability makes that risk server-enforced and concrete instead of client-suggested and generic: the API refuses downgrades without explicit acknowledgement, names the exact migrations the target version lacks, and maintains a last-known-good version per app so rollback has an evidence-based target. It never claims to make downgrades safe — it makes them informed.

## Requirements

### Requirement: Server-side downgrade guard [MVP]

When the requested version is lower than the installed version (`version_compare`), `installVersion` MUST refuse with a structured 409 response (category `downgrade_guard`, hint naming both versions) unless the request carries `allowDowngrade: true`. The guard MUST apply to every consumer of the install path (HTTP, CLI, future background jobs) and MUST run server-side before any download. Dry-run requests MUST evaluate and report the guard without requiring the flag.

#### Scenario: API downgrade without acknowledgement

@e2e tests/e2e/versions.spec.ts

- GIVEN `openregister` installed at 2.5.0
- WHEN `POST .../versions/2.3.0/install` is called without `allowDowngrade`
- THEN the response MUST be 409 with category `downgrade_guard` naming 2.5.0 → 2.3.0
- AND nothing MUST be downloaded or changed

#### Scenario: Acknowledged downgrade proceeds

@e2e tests/e2e/forge.spec.ts

- WHEN the same request carries `allowDowngrade: true`
- THEN the install MUST proceed through the normal flow (integrity checks, backup, finalize)

#### Scenario: Upgrades are unaffected

@e2e tests/e2e/version-management.spec.ts

- GIVEN installed 2.3.0
- WHEN 2.5.0 is requested without `allowDowngrade`
- THEN the guard MUST NOT trigger

---

### Requirement: Migration diff on downgrade [MVP]

For a downgrade (acknowledged or dry-run), after extracting the target archive and before any file swap, the system MUST compare migration step files (`lib/Migration/Version*.php`) between the installed copy and the target archive and report the steps present in the installed version but absent from the target. The response (and dry-run result) MUST include this list as `orphanedMigrations`; the UI downgrade dialog MUST display it. An empty diff MUST be reported as such ("no schema steps differ"). Diff failure (unreadable archive layout) MUST degrade to the generic warning, never block an acknowledged downgrade.

#### Scenario: Diff names the orphaned steps

@e2e exclude the fixture app ships no migrations; MigrationDiffer is unit-tested with fabricated Version*.php fixtures.

- GIVEN installed 2.5.0 contains `Version2040Date20260101000000.php` and target 2.3.0 does not
- WHEN a dry-run downgrade to 2.3.0 runs
- THEN `orphanedMigrations` MUST contain `Version2040Date20260101000000`
- AND the downgrade dialog MUST render the list

#### Scenario: No schema drift

@e2e exclude the empty-diff case is unit-tested in MigrationDiffer.

- GIVEN target and installed ship identical migration sets
- WHEN the dry-run runs
- THEN `orphanedMigrations` MUST be an empty list and the UI MUST say no schema steps differ

#### Scenario: Diff failure degrades gracefully

@e2e exclude an unreadable migration directory is not reproducible in e2e; the degrade path is unit-tested.

- GIVEN a target archive whose migration directory cannot be read
- WHEN an acknowledged downgrade runs
- THEN the install MUST proceed with the generic schema warning and the response MUST note the diff was unavailable

---

### Requirement: Last-known-good version record [MVP]

After every successful finalize, the system MUST record `lkg.{appId}` (JSON: `version`, `recordedAt` ISO-8601 UTC, `sourceId`) via `IAppConfig`. Failed or reverted installs MUST NOT touch the record. `GET /api/apps` MUST expose the record per app. The UI MUST offer "Roll back to last known good" on apps whose installed version differs from the record; the action MUST route through the standard install flow, inheriting the downgrade guard and migration diff.

#### Scenario: Success updates the record

@e2e tests/e2e/install-effects.spec.ts

- GIVEN `openregister` finalizes 2.5.0 successfully
- THEN `lkg.openregister` MUST record version 2.5.0 with timestamp and source

#### Scenario: Failure preserves the record

@e2e tests/e2e/install-effects.spec.ts

- GIVEN `lkg.openregister` records 2.5.0
- WHEN an install of 2.6.0 fails and is reverted
- THEN `lkg.openregister` MUST still record 2.5.0

#### Scenario: One-click rollback target

@e2e exclude the Roll-back-to-last-known-good UI action needs installed!=lkg; the lkg record is e2e-covered and the action is covered by App.vue vitest.

- GIVEN installed 2.6.0 (broken) and `lkg.openregister` = 2.5.0
- WHEN the admin clicks "Roll back to last known good"
- THEN the standard install flow for 2.5.0 MUST start, presenting the downgrade dialog with the migration diff

### Requirement: An App Store package is verified against Nextcloud code signing before install [MVP]

Before an App Store release is downloaded, the system MUST check the release certificate
against the Nextcloud code-signing root (`resources/codesigning/root.crt`) and its revocation
list (`root.crl`): the CRL signature MUST validate, the certificate serial MUST NOT be revoked,
the certificate MUST be issued by the trusted root, and its common name MUST equal the app id.
After the download the system MUST verify the archive's SHA-512 signature with that
certificate's public key. Any failure MUST stop the install before a file is swapped. A forge
release carries no signature; it is checked by SHA-256 instead (external-sources, artifact-cache).
Code: `lib/Service/SelectedReleaseInstallerService.php` (`verifyCertificate`, the
`openssl_verify` check).

#### Scenario: A certificate issued to another app is refused

@e2e exclude needs a release signed by the Nextcloud code-signing root for a different app id, which CI cannot produce.

- **GIVEN** an App Store release of `openregister` whose certificate CN is `calendar`
- **WHEN** the admin installs that version
- **THEN** the install MUST fail with "App with id openregister has a cert issued to calendar"
- **AND** no file of the installed app MUST change

#### Scenario: A tampered archive is refused

@e2e exclude needs a store archive altered in transit; the signature step is a single openssl_verify call on the downloaded bytes.

- **GIVEN** a valid certificate and an archive whose bytes do not match the release signature
- **WHEN** the install verifies the download
- **THEN** the install MUST fail with "Release signature verification failed."
- **AND** no file of the installed app MUST change

### Requirement: Maintenance mode is held around a real install [MVP]

For a real install, the system MUST switch Nextcloud maintenance mode on before it touches the
app's files when maintenance mode was off, and MUST switch it off again when the install ends,
whether it succeeded, failed or threw. When maintenance mode was already on, the system MUST
leave it on. A dry run MUST NOT switch maintenance mode on, so a preview never locks users out
(#427). This holds for installs from the Apps tab, `occ versioniq:install` and the
automatic-update job, which share `InstallerService`. Code: `lib/Service/InstallerService.php`
(`$maintenanceWasSet` and the `finally` block).

#### Scenario: A real install takes and releases maintenance mode

@e2e exclude maintenance mode locks every other request on the shared CI instance, including the test's own. No unit test asserts the on and off pair yet (InstallerServiceTest only runs with maintenance already on); listed as a gap in the PR.

- **GIVEN** maintenance mode is off
- **WHEN** the admin installs another version of an app and the install fails halfway
- **THEN** maintenance mode MUST have been on while files were swapped
- **AND** maintenance mode MUST be off again when the response returns

#### Scenario: A dry run leaves maintenance mode alone

@e2e exclude covered by tests/unit/Service/InstallerDryRunSideEffectsTest.php.

- **GIVEN** maintenance mode is off
- **WHEN** the admin runs a dry run of an install
- **THEN** maintenance mode MUST NOT be switched on at any point

### Requirement: The app card offers the version before the last update as a rollback target

The last-known-good record MUST keep the version that ran before the last successful install as `previous`. The app card MUST offer "Roll back to <previous>". When the last-known-good version differs from both the installed version and `previous`, the card MUST also offer "Roll back to the last good version (<version>)".

#### Scenario: An admin undoes an update made through Versioniq

- **GIVEN** Dossiq ran 0.4.44 and an admin updated it to 0.4.48 through Versioniq
- **WHEN** the admin opens the Apps tab
- **THEN** the Dossiq card MUST offer "Roll back to 0.4.44"

#### Scenario: Two rollback targets

- **GIVEN** the last good version of Dossiq is 0.4.46, `previous` is 0.4.44 and 0.4.48 was put in place outside Versioniq
- **WHEN** the admin opens the Apps tab
- **THEN** the card MUST offer "Roll back to 0.4.44" and "Roll back to the last good version (0.4.46)"

### Requirement: A real install keeps a restorable backup of the app folder and its tables

Before a real install replaces an app, Versioniq MUST keep a backup of the old app folder and of the database tables the app's own migrations created. The backup MUST be kept for the number of days set in `backup.keep_days` (default 14) and then removed. The install result MUST say that a backup was made, its size and until when it is kept. With `backup.keep_days` set to 0 no backup is made and the result MUST say so.

#### Scenario: The install result names the backup

- **GIVEN** `backup.keep_days` is 14
- **WHEN** an admin updates Dossiq on 1 October
- **THEN** the result MUST show "Backup made: database and app folder" with its size and "kept until 15 Oct"

#### Scenario: Expired backups are removed

- **GIVEN** a backup older than `backup.keep_days`
- **WHEN** the daily prune runs
- **THEN** that backup MUST be removed

### Requirement: Restoring a broken install puts back files and tables

When an install ends installed-but-broken, the result MUST offer "Restore installation". Restoring MUST put back the old app folder and the app's tables from the backup made before that install, then the previous installed version and enabled state. It MUST require password confirmation. Without a backup it MUST refuse and point to a rollback instead.

#### Scenario: A failed migration is undone

- **GIVEN** an update of Dossiq to 0.4.50 applied one migration and then failed to finalize
- **WHEN** the admin chooses "Restore installation" and confirms their password
- **THEN** Dossiq MUST run 0.4.48 again with the tables as they were before the update
- **AND** the audit trail MUST record the restore
