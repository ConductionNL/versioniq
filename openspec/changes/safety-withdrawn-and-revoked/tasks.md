# safety-withdrawn-and-revoked tasks

## 1. Revocation list

- [x] 1.1 Add `RevocationList` (shipped list, fetched list with signature and CRL number check, daily refetch, empty address switches it off) and make `verifyCertificate()` read from it. Verify: `tests/unit/Service/Safety/RevocationListTest.php` with a valid newer list, a list with a bad signature (ignored) and an older list (ignored).
  - Done: `RevocationList` (`current()`, `refreshIfDue()`, `url()`; address `safety.crl_url`, default the `root.crl` in the server repository, kept in app data `codesigning`) reads lists through `CrlDocument`, which checks the signature with OpenSSL against `root.crt` and compares CRL numbers. `SelectedReleaseInstallerService::verifyCertificate()` takes its list from it. `RevocationListTest` signs real lists with keys OpenSSL makes in the test (`tests/unit/Support/CodeSigningFixtures.php`): newer list kept, bad signature, older list, list without a number, empty address, once a day, failed fetch, and an install checking against the same list.

## 2. Recheck installed apps

- [x] 2.1 Add `InstalledCertificateCheck` and `CertificateRecheckJob`, register the job, add `certificateRevoked` to `GET /api/apps`, and the `certificate_revoked` subject. Verify: `tests/unit/BackgroundJob/CertificateRecheckJobTest.php` (one notification per app and serial, shipped apps skipped, disable only with the switch on, audit row on disable).
  - Done: `InstalledCertificateCheck` reads the `certificate` from `appinfo/signature.json` of every installed, not shipped, App Store app; `RevokedAppResponder` records the serial in `RevokedCertificateStore` (`revoked.<appId>`), notifies once per app and serial, and with `safety.disable_revoked` on disables the app once with a `revoked_disable` audit row. `CertificateRecheckJob` (daily, in `info.xml`) refreshes the list first. Every card of `GET /api/apps` carries `certificateRevoked`; the Notifier renders `certificate_revoked` in the D4 wording. Tests: `CertificateRecheckJobTest`, `NotifierCertificateRevokedTest`, the `apps` case of `ApiReadEndpointsGoldenTest`.
- [ ] 2.2 Add `safety.crl_url` and `safety.disable_revoked` to `InstanceSettings` and the Settings tab. Verify: `InstanceSettingsPanel.spec.ts`.
- [ ] 2.3 Show the "Certificate revoked" badge with the wording of design D4. Verify: a vitest spec for the badge.

## 3. Withdrawn versions

- [ ] 3.1 Add `installedWithdrawn` to the availability sweep and the card badge. Verify: a unit test where a failed listing never marks a version withdrawn.
  - Sweep half done: `WithdrawnVersionStore` and `installedWithdrawn` on every swept row (null when the listing failed or named no versions); `tests/unit/Service/Availability/WithdrawnVersionsTest.php`. The card badge is left.
- [x] 3.2 Refuse a withdrawn target in `installAppVersion()` with category `withdrawn`. Verify: `tests/unit/Service/InstallerServiceTest.php` with a stale catalogue that still lists the version; `tests/e2e/withdrawn.spec.ts` removes a release from the forge fixture and sees the badge.
  - Done in code: `InstallerService::withdrawnRefusal()` answers 409 with category `withdrawn` before any source is asked; cases in `InstallerServiceInstallGoldenTest`. The e2e `withdrawn.spec.ts` is left.

## 4. Close

- [ ] 4.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `rel-withdrawn-versions`, `saf-revoked-certificate` and `adv-malicious-package` to `built` with evidence lines, then archive this change.
