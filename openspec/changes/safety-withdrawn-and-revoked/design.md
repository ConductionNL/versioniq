# Design: safety-withdrawn-and-revoked

Read against `development` at 02e1050 (2026-09-27).

## Context

- `SelectedReleaseInstallerService::verifyCertificate()` (`lib/Service/SelectedReleaseInstallerService.php:133-196`) loads `resources/codesigning/root.crt` and `root.crl` from the server root, validates the CRL signature against the root chain, refuses a revoked serial, and checks that the certificate's CN is the app id. It runs at install (line 499) and for a cached artifact (line 533), never afterwards.
- Every signed App Store app ships `appinfo/signature.json`, which carries the app's certificate next to the file hashes the server's integrity checker reads.
- `InstallerService::installAppVersion()` resolves the release from the source before anything else and answers 404 "Requested version not found in source metadata." when the version is not listed (`lib/Service/InstallerService.php:569-580`). `AppStoreSource` serves a stale catalogue when the store fails (stale-if-error, `lib/Service/Source/AppStoreSource.php:254-275`), so a version the store withdrew can still resolve while the store is down.
- Notifications follow `PinDriftHandler` (`lib/Service/Pin/PinDriftHandler.php:87-99`): one per admin, once per event, rendered by `lib/Notification/Notifier.php`.
- `inventory-pending-updates` specifies the availability sweep this change adds a field to.

## Goals and non-goals

Goals: recheck installed apps against a current revocation list, say when the running version was withdrawn, and never reinstall a withdrawn version by accident.

Non-goals: bundled libraries, forge certificates, uninstalling.

## Decisions

### D1. One revocation list, the newest that validates

`RevocationList::current()` returns the server's `root.crl`, unless a newer list fetched from `safety.crl_url` validates against `root.crt` and carries a higher CRL number, in which case that one wins. The default address is the `root.crl` in the Nextcloud server repository's main branch; an empty address switches fetching off. The fetched list is kept in app data folder `codesigning` and refetched daily. `verifyCertificate()` reads its list from `RevocationList` as well, so install and recheck can never disagree.

Alternative considered: trust the fetched list as is. Rejected: the list decides what gets disabled, so it must carry Nextcloud's signature.

### D2. The daily recheck

`CertificateRecheckJob` (daily `TimedJob`) reads `appinfo/signature.json` of every installed app bound to the App Store, takes the `certificate`, and checks its serial against `RevocationList::current()`. A newly revoked app gets `certificate_revoked` notifications (once per app and serial) and a flag stored in app config that the Apps tab reads through `GET /api/apps` (field `certificateRevoked`). When `safety.disable_revoked` is on, the job also disables the app with `IAppManager::disableApp()` and writes an audit row. Shipped apps are skipped: their integrity is the server's own check.

### D3. Withdrawn versions

The availability sweep keeps, per app, the versions its last successful listing contained. A version that an earlier successful listing contained and a later successful listing does not is recorded in `withdrawnVersions` (app config `withdrawn.<appId>`, capped at 50 entries). When the installed version is plain `major.minor.patch` and missing from a successful listing, the sweep also sets `installedWithdrawn`. The card shows "Version withdrawn by the publisher". `installAppVersion()` refuses a target version recorded in `withdrawnVersions`, even when a stale catalogue still resolves it, with category `withdrawn` and a hint to pick another version. A listing that failed changes neither record.

Alternative considered: auto-roll to another version. Rejected: the admin decides, as everywhere else in Versioniq.

### D4. Wording

The badge tooltip and the notification say: "Nextcloud revoked the signing certificate of this app. Nextcloud revokes a certificate to withdraw an app it knows to be compromised or malicious." That is the known-malicious signal for Nextcloud apps; the proposal says so, and does not claim a malware feed Versioniq does not read.

## Risks and trade-offs

- [Auto-disable takes down an app users rely on] → off by default, audited, and the card offers Enable again.
- [The fetched list is unreachable] → the server's shipped list still applies; the recheck never gets weaker than install time.
- [A publisher re-tags a release, so the version disappears briefly] → the marker needs a listing without error, and it clears on the next sweep that lists the version again.

## Migration

No schema change. The new job is registered in `info.xml`. Rollback: revert; delete the `codesigning` app data folder if wanted.
