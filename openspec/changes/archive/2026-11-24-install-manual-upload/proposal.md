---
kind: code
---

# Proposal: install-manual-upload

## Why

Some apps never reach the App Store or a forge Versioniq can read: a build a supplier mails over, a patched release from a support contract, an app from a closed network. Today an admin who has such an archive has to copy files onto the server by hand, outside every check Versioniq runs. WordPress has let an admin upload a plugin archive and replace the installed version from the admin page for years.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `ins-manual-upload` | no | No archive upload. Sources are the App Store and forge releases only (`lib/Controller/ApiController.php:256`). |

### Demand

No demand row. The row is in the product's core area (install), and one competitor is rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- Easy Updates Manager, through WordPress core: "Upload Plugin installs a zip, and replaces an installed plugin with the uploaded version (https://wordpress.org/documentation/article/manage-plugins/#upload-via-wordpress-admin)".
- Nextcloud is rated no; Renovate and Dependabot unknown; OSV-Scanner no.

## What changes

- The app detail gets "Install from a file". An admin picks a `.tar.gz` archive; Versioniq reads the app id and version from its `appinfo/info.xml` and shows them, with the archive's SHA-256, before anything is installed.
- If the App Store lists that app and version, Versioniq verifies the upload against the store's signature and certificate, and the install counts as a signed install.
- Otherwise the upload is unsigned. Versioniq installs an unsigned upload only when an admin switched "Allow unsigned uploads" on in the Settings tab, and only after the admin pasted the SHA-256 they expect or ticked that they accept the shown one.
- The upload then runs the same pipeline as a forge install: app id and version checks, the downgrade and pin guards, the migration diff, backup and restore, finalize, audit. The source binding does not change, and the verified archive goes into the artifact cache so a rollback can use it.

## Scope

In scope: the upload route, signed and unsigned verification, the setting, the dialog, the audit entry, tests.

Out of scope:
- Uploading to a source list or making an upload a source. It is a one-off install.
- Zip archives. Nextcloud apps ship as `.tar.gz`, which is what both installers already extract.

## Impact

- New: `lib/Service/Installer/UploadedArchive.php`, `src/dialogs/UploadInstallDialog.vue`.
- Changed: `lib/Service/ExternalReleaseInstallerService.php` (a local-file entry point that skips the download), `lib/Service/SelectedReleaseInstallerService.php` (verify a local file against a store release), `lib/Service/InstallerService.php` (`installUploadedArchive()`), `lib/Controller/ApiController.php` (upload route), `lib/Service/Settings/InstanceSettings.php` (`install.allow_unsigned_upload`), `src/App.vue`, `l10n`.
- ADDED requirement in `external-sources`.

### MCP coverage

No MCP surface in this change: uploading code stays a human action with a password.

## Rollback

Revert the change. Installed apps stay installed; the setting is an inert app config key.
