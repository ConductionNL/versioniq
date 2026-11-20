# safety-withdrawn-and-revoked tasks

## 1. Revocation list

- [x] 1.1 Add `RevocationList` (shipped list, fetched list with signature and CRL number check, daily refetch, empty address switches it off) and make `verifyCertificate()` read from it. Verify: `tests/unit/Service/Safety/RevocationListTest.php` with a valid newer list, a list with a bad signature (ignored) and an older list (ignored).
  - Done: `RevocationList` (`current()`, `refreshIfDue()`, `url()`; address `safety.crl_url`, default the `root.crl` in the server repository, kept in app data `codesigning`) reads lists through `CrlDocument` and keeps the fetched one through `AppDataFiles` (shared with `LocalFeedCopy`), which checks the signature with OpenSSL against `root.crt` and compares CRL numbers. `SelectedReleaseInstallerService::verifyCertificate()` takes its list from it, resolved from the container. `RevocationListTest` signs real lists with keys OpenSSL makes in the test (`tests/unit/Support/CodeSigningFixtures.php`): newer list kept, bad signature, older list, list without a number, empty address, once a day, failed fetch, and an install checking against the same list.

## 2. Recheck installed apps

- [x] 2.1 Add `InstalledCertificateCheck` and `CertificateRecheckJob`, register the job, add `certificateRevoked` to `GET /api/apps`, and the `certificate_revoked` subject. Verify: `tests/unit/BackgroundJob/CertificateRecheckJobTest.php` (one notification per app and serial, shipped apps skipped, disable only with the switch on, audit row on disable).
  - Done: `InstalledCertificateCheck` reads the `certificate` from `appinfo/signature.json` of every installed, not shipped, App Store app; `RevokedAppResponder` records the serial in `RevokedCertificateStore` (`revoked.<appId>`), notifies once per app and serial, and with `safety.disable_revoked` on has `RevokedAppDisabler` disable the app once with a `revoked_disable` audit row. `CertificateRecheckJob` (daily, in `info.xml`) refreshes the list first. Every card of `GET /api/apps` carries `certificateRevoked` (from `InstallerService`); the Notifier renders `certificate_revoked` in the D4 wording. Tests: `CertificateRecheckJobTest`, `NotifierCertificateRevokedTest`, `InstallerServiceAppListGoldenTest`.
- [x] 2.2 Add `safety.crl_url` and `safety.disable_revoked` to `InstanceSettings` and the Settings tab. Verify: `InstanceSettingsPanel.spec.ts`.
  - Done: `InstanceSettings` reads and writes `crlUrl` (the address in effect; an empty one is kept and switches fetching off, the default is not stored) and `disableRevoked`, validated before anything is written; `InstanceSettingsPanel.vue` shows both under "Revoked certificates" and sends them only when the server reports them. Tests: `InstanceSettingsTest`, `InstanceSettingsPanel.spec.ts`.
- [x] 2.3 Show the "Certificate revoked" badge with the wording of design D4. Verify: a vitest spec for the badge.
  - Done: `SafetyBadges.vue` shows "Certificate revoked" on the card when `certificateRevoked` is set, the D4 text as its tooltip. Tests: `SafetyBadges.spec.ts`, `App.safetyBadges.spec.ts`.

## 3. Withdrawn versions

- [x] 3.1 Add `installedWithdrawn` to the availability sweep and the card badge. Verify: a unit test where a failed listing never marks a version withdrawn.
  - Sweep half done: `WithdrawnVersionStore` and `installedWithdrawn` on every swept row (null when the listing failed or named no versions); `tests/unit/Service/Availability/WithdrawnVersionsTest.php`.
  - Card badge done: `SafetyBadges.vue` shows "Version withdrawn by the publisher" when the snapshot row of `GET /api/updates` has `installedWithdrawn: true` (nothing for null). Tests: `SafetyBadges.spec.ts`, `App.safetyBadges.spec.ts`.
- [x] 3.2 Refuse a withdrawn target in `installAppVersion()` with category `withdrawn`. Verify: `tests/unit/Service/InstallerServiceTest.php` with a stale catalogue that still lists the version; `tests/e2e/withdrawn.spec.ts` removes a release from the forge fixture and sees the badge.
  - Done in code: `InstallerService::withdrawnRefusal()` answers 409 with category `withdrawn` before any source is asked; cases in `InstallerServiceInstallGoldenTest`. The e2e `withdrawn.spec.ts` is left.
  - Written: `tests/e2e/withdrawn.spec.ts`; waits for the Playwright job. Its badge test targets the card text "Version withdrawn by the publisher" (design D3), which waits for the badge of 3.1.

## 4. Close

- [x] 4.1 Add strings to `l10n/en` and `l10n/nl` (verify `npm run check:l10n-js`), set the matrix rows `rel-withdrawn-versions`, `saf-revoked-certificate` and `adv-malicious-package` to `built` with evidence lines, then archive this change.
  - Strings are in: the badge, settings and notification strings are in `l10n/en.json` and `l10n/nl.json`, `l10n/*.js` rebuilt, `npm run check:l10n-js` and `scripts/check-l10n-keys.cjs` pass. The matrix rows and the archive are left.
