# install-manual-upload tasks

## 1. Inspect

- [ ] 1.1 Add `UploadedArchive` (temporary storage, `info.xml` read, SHA-256, one-hour expiry) and `POST /api/upload/inspect`. Verify: `tests/unit/Service/Installer/UploadedArchiveTest.php` with a valid archive, one without `info.xml`, and one over the cap; `tests/unit/Controller/ApiTest.php` asserts 403 for a non-admin.
- [ ] 1.2 Check a store-listed upload against the store signature. Verify: a unit test with a fixture store release whose signature matches, and one where the bytes differ.

## 2. Install

- [ ] 2.1 Add `installUploadedArchive()` with the guards of `installAppVersion()`, the signed and unsigned pipelines, and `POST /api/upload/{token}/install`. Verify: unit tests for a signed upload, an unsigned upload with the setting off (refused), a wrong expected SHA-256 (refused), a pinned app (409), a downgrade without acknowledgement (409).
- [ ] 2.2 Write the audit row with source `upload` and the archive to the artifact cache. Verify: a unit test on both writes.
- [ ] 2.3 Add `install.allow_unsigned_upload` to `InstanceSettings` and the Settings tab. Verify: `InstanceSettingsPanel.spec.ts`.

## 3. Page

- [ ] 3.1 Add `UploadInstallDialog.vue` (pick file, show app, version, SHA-256 and signed state, expected SHA-256 field, Install). Verify: `UploadInstallDialog.spec.ts`.
- [ ] 3.2 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.3 Add `tests/e2e/upload.spec.ts`: upload a fixture archive with unsigned uploads on, install it, and see the History row with source `upload`.

## 4. Close

- [ ] 4.1 Set the matrix row `ins-manual-upload` to `built` with evidence lines, then archive this change.
