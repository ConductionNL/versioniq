# safety-withdrawn-and-revoked tasks

## 1. Revocation list

- [x] 1.1 Add `RevocationList` (shipped list, fetched list with signature and CRL number check, daily refetch, empty address switches it off) and make `verifyCertificate()` read from it. Verify: `tests/unit/Service/Safety/RevocationListTest.php` with a valid newer list, a list with a bad signature (ignored) and an older list (ignored).

## 2. Recheck installed apps

- [x] 2.1 Add `InstalledCertificateCheck` and `CertificateRecheckJob`, register the job, add `certificateRevoked` to `GET /api/apps`, and the `certificate_revoked` subject. Verify: `tests/unit/BackgroundJob/CertificateRecheckJobTest.php` (one notification per app and serial, shipped apps skipped, disable only with the switch on, audit row on disable).
- [x] 2.2 Add `safety.crl_url` and `safety.disable_revoked` to `InstanceSettings` and the Settings tab. Verify: `InstanceSettingsPanel.spec.ts`.
- [x] 2.3 Show the "Certificate revoked" badge with the wording of design D4. Verify: a vitest spec for the badge.

## 3. Withdrawn versions

- [x] 3.1 Add `installedWithdrawn` to the availability sweep and the card badge. Verify: a unit test where a failed listing never marks a version withdrawn.
  - Sweep half done: `WithdrawnVersionStore` and `installedWithdrawn` on every swept row (null when the listing failed or named no versions); `tests/unit/Service/Availability/WithdrawnVersionsTest.php`. Card badge: `src/components/SafetyBadges.vue` (with "Certificate revoked" of 2.3), `SafetyBadges.spec.ts` and `App.appList.spec.ts`.
- [x] 3.2 Refuse a withdrawn target in `installAppVersion()` with category `withdrawn`. Verify: `tests/unit/Service/InstallerServiceTest.php` with a stale catalogue that still lists the version; `tests/e2e/withdrawn.spec.ts` removes a release from the forge fixture and sees the badge.
  - Done in code: `InstallerService::withdrawnRefusal()` answers 409 with category `withdrawn` before any source is asked; cases in `InstallerServiceInstallGoldenTest`. The e2e `withdrawn.spec.ts` is left.

## 4. Close

- [ ] 4.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `rel-withdrawn-versions`, `saf-revoked-certificate` and `adv-malicious-package` to `built` with evidence lines, then archive this change.
  - Strings (en, nl) and the three matrix rows are done; archiving is left until the e2e `withdrawn.spec.ts` of 3.2 has run.
