# install-multi-instance-promotion tasks

## 1. Connections

- [ ] 1.1 Add `lib/Service/Instance/InstanceConnection.php` and `lib/Service/Instance/InstanceConnectionStore.php` (design D1): JSON under `instance.{id}`, https-only address checks, password encrypted with `ICrypto`, the local stage under `instance.self`. Verify: `tests/unit/Service/Instance/InstanceConnectionStoreTest.php` covers an http address refused, a stored value that holds no plaintext password, and a malformed value read as absent.
- [ ] 1.2 Add `GET/POST /api/instances` and `PUT/DELETE /api/instances/{id}` on `lib/Controller/InstanceController.php`, admin-only, writes password-confirmed, audited as `instance_connection`. Verify: `tests/unit/Controller/InstanceControllerTest.php` asserts 403 for a non-admin, no password in any response, and one audit row per change.

## 2. Manifest and remote reads

- [ ] 2.1 Add `lib/Service/Instance/ManifestBuilder.php` and `GET /api/instance/manifest` (design D3), admin-only. Verify: `tests/unit/Service/Instance/ManifestBuilderTest.php` covers an App Store app (null digest), a forge app (recorded digest), `runningSince` from the last-known-good record, from an audit row, and null.
- [ ] 2.2 Add `lib/Service/Instance/RemoteInstanceReader.php` (design D4): Basic auth, `OCS-APIREQUEST`, 15 s timeout, `allow_local_address` from `allow_local_remote_servers`, the result cached under `instance.{id}.manifest`. Verify: `tests/unit/Service/Instance/RemoteInstanceReaderTest.php` covers 200, 401 (not an admin there), 404 (older Versioniq), a timeout that keeps the previous manifest, and that the password never reaches a log call.
- [ ] 2.3 Add `POST /api/instances/refresh`. Verify: a controller test that one failing connection does not stop the others.

## 3. Promotion

- [ ] 3.1 Add the optional `expectedSha` argument to `InstallerService::installAppVersion()` (design D6 step 3). Verify: `tests/unit/Service/InstallerServiceShaPinningTest.php` gains cases for a matching digest, a different recorded digest (refused before download), and no recorded digest (added, then enforced).
- [ ] 3.2 Add `lib/Service/Instance/PromotionService.php` with `plan()` and `promote()`, the `promotion.min_days` setting in `InstanceSettings`, and the audit operation `promote` in `AuditLogger`. Verify: `tests/unit/Service/Instance/PromotionServiceTest.php` covers an upgrade, a downgrade without and with acknowledgement, a pinned app, the waiting period not met, unknown `runningSince`, the override written into the audit message, and a remote that no longer runs the planned version.
- [ ] 3.3 Add `POST /api/app/{appId}/promote`, password-confirmed. Verify: `tests/unit/Controller/InstanceControllerTest.php` asserts 403 for a non-admin and 409 with the days left.

## 4. Page

- [ ] 4.1 Add `src/components/InstancesPanel.vue`, the Instances tab in `src/App.vue`, `src/dialogs/InstanceConnectionDialog.vue` and `src/dialogs/PromoteDialog.vue` (design D5, D6). Verify: `src/components/InstancesPanel.spec.ts` covers a differing row, the "differs only" filter, a stale remote with its error, and an older Versioniq; `src/dialogs/PromoteDialog.spec.ts` covers the waiting-period message, the downgrade confirmation and the rollback link.
- [ ] 4.2 Add the waiting-period field to `src/components/InstanceSettingsPanel.vue`. Verify: `src/components/InstanceSettingsPanel.spec.ts` saves 14 and rejects 91.
- [ ] 4.3 Add the new strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 4.4 Add `tests/e2e/instances.spec.ts`: read `GET /api/instance/manifest` on the test instance as admin and as a non-admin, and assert the Instances tab shows this instance's column with the fixture app's version. A second instance is not available in the e2e setup and connections accept https only, so the remote read and the promotion are covered by the unit tests of 2.2 and 3.2. Verify: the spec passes in `npm run test:e2e`.

## 5. Commands

- [ ] 5.1 Add `lib/Command/ListInstances.php` (`versioniq:instances`, `--json`, `--refresh`) and `lib/Command/PromoteVersion.php` (`versioniq:promote`, `--dry-run`, `--allow-downgrade`, `--override-min-days`, `--continue`), registered in `appinfo/info.xml`. Verify: `tests/unit/Command/PromoteVersionTest.php` covers exit codes 0, 10, 11 and a passed-through install code; extend `tests/e2e/cli.spec.ts` with one `versioniq:instances --json` run.

## 6. Close

- [ ] 6.1 Set the matrix rows `inv-multi-instance` and `ins-staged-promotion` to `built` with evidence lines, then archive this change.
