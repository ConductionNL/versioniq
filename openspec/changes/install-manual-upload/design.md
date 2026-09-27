# Design: install-manual-upload

Read against `development` at 02e1050 (2026-09-27).

## Context

- `ExternalReleaseInstallerService::installFromExternalRelease()` (`lib/Service/ExternalReleaseInstallerService.php:118-340`) downloads the archive (line 147-184), checks a recorded or sibling SHA-256 (line 206-214), extracts it (`extractArchive`, line 515), validates `info.xml` against the expected app id and version (`parseAndValidateInfoXml`, line 543), computes the migration diff, backs up the old folder, swaps files and runs `InstallFinalizer` (line 309-333), restoring on failure (`restoreFromBackup`, line 676).
- `SelectedReleaseInstallerService` verifies an App Store archive's signature and certificate (`verifyCertificate`, `lib/Service/SelectedReleaseInstallerService.php:133-196`, called at 499 and for a cached artifact at 533). The store release carries `signature` and `certificate`.
- `InstallerService::installAppVersion()` (`lib/Service/InstallerService.php:407`) holds the guards: manageable app, downgrade guard, pin guard, pre-flight writability, maintenance mode.
- The artifact cache (`lib/Service/Cache/ArtifactCache.php`) keeps verified archives for a rollback when the source is gone.
- The install route uses non-strict password confirmation (`lib/Controller/ApiController.php:468`).

## Goals and non-goals

Goals: install an app version from an archive file with every check Versioniq applies to a forge release, and the store's signature when it has one.

Non-goals: upload as a source, zip archives.

## Decisions

### D1. Read first, install second

`POST /api/upload/inspect` (admin, strict password confirmation, multipart, capped at the server's upload limit and 256 MB, the artifact cache's own read cap) stores the file in a temporary app data folder, extracts `appinfo/info.xml` without extracting the rest, and returns `{token, appId, version, sha256, signedByStore}`. `signedByStore` is true when the App Store lists that app and version and the store's signature verifies against the uploaded bytes. Nothing is installed.

### D2. Install by token

`POST /api/upload/{token}/install` (admin, strict confirmation) runs `InstallerService::installUploadedArchive(token, expectedSha256, acceptShown)`. It applies every guard of `installAppVersion()` in the same order, then:

- signed by the store: runs the signed installer's pipeline on the local file, so the certificate and CN checks apply;
- unsigned: refuses unless `install.allow_unsigned_upload` is on, and unless the admin pasted a SHA-256 that matches or ticked that they accept the shown one; then runs the external installer's pipeline from the extracted archive onwards.

The temporary file is removed after the install or after an hour, whichever comes first.

Alternative considered: one request that uploads and installs. Rejected: the admin would approve an app id and version they had not seen.

### D3. What the record says

The audit row uses source id `upload`, carries the SHA-256 in its message, and says signed or unsigned. The source binding does not change, so the next version list still comes from the bound source. The verified archive is written to the artifact cache under its app id and version.

### D4. Unsigned is off by default

An unsigned upload bypasses the trusted-source allowlist by nature. The setting is off by default, and the dialog says in plain words that an unsigned archive runs whatever code it contains.

## Risks and trade-offs

- [Uploaded code is arbitrary code] → strict password confirmation, unsigned off by default, the SHA-256 shown before install, an audit row.
- [Large uploads] → capped; the server's own upload limit applies first.
- [A store-listed version with different bytes] → `signedByStore` is false, so it counts as unsigned and needs the setting.

## Migration

No schema change. Rollback: revert.
