---
kind: code
---

# Proposal: safety-withdrawn-and-revoked

## Why

Three things tell an admin that code they run should not be trusted any more: the publisher withdrew the version, Nextcloud revoked the app's signing certificate, or the app is known to be malicious. Versioniq checks the revocation list once, at install time, against the list the server shipped with. After that it never looks again, and a withdrawn version simply disappears from the list without a word.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers three rows that share one check of what is already installed.

| Row | Rating now | What is missing |
|---|---|---|
| `rel-withdrawn-versions` | partial, built | The missing half: a withdrawn version drops out of the list, but nothing tells the admin that the version they run was withdrawn. |
| `saf-revoked-certificate` | partial, built | The missing half: an App Store install is refused when the certificate is on the server's shipped `root.crl`, but installed apps are never rechecked when a certificate is revoked later, and the list is only as fresh as the server release. |
| `adv-malicious-package` | no | No alert when an installed app is known to be malicious. |

### Demand

- `rel-withdrawn-versions`: feature request, https://github.com/renovatebot/renovate/issues/13012 (ignore retracted versions).
- `saf-revoked-certificate`: competitor changelog, https://github.com/nextcloud/server/pull/64723. "Update code signing revocation list", merged 2026-09-24; the list was refreshed three times in the window (also PRs 64624 and 64454), so revocations happen after servers are installed.
- `adv-malicious-package`: competitor changelog, https://github.blog/changelog/2026-07-28-dependabot-alerts-on-malicious-packages-across-more-ecosystems/.

### Competitors rated yes (evidence quoted from the matrix)

- `rel-withdrawn-versions`, Dependabot rated yes: "yanked releases are dropped before a target is chosen (common/lib/dependabot/package/package_latest_version_finder.rb:145, :160) and Go module retractions are handled".
- `adv-malicious-package`, Dependabot rated yes: "Dependabot malware alerts flag dependencies matched to malware advisories, now fed from the OpenSSF malicious-packages repository (https://docs.github.com/en/code-security/concepts/supply-chain-security/malware-alerts)". OSV-Scanner rated yes: "OSV.dev imports the OpenSSF Malicious Packages source (https://google.github.io/osv.dev/data/), and the scanner reports every OSV record matching an installed package".
- `saf-revoked-certificate`: no competitor rated yes. Nextcloud is partial: "lib/private/Installer.php:181-195 checks the app certificate against the bundled resources/codesigning/root.crl ... Already enabled apps are not rechecked ... the CRL changes only with a server release".

## What changes

- A daily check reads the signing certificate of every installed App Store app from its `appinfo/signature.json` and checks it against the newest revocation list Versioniq can trust: the one the server shipped, or a newer one fetched from a configurable address and accepted only when its signature validates against Nextcloud's root certificate.
- An app whose certificate is revoked gets a "Certificate revoked" badge and every admin is notified. An admin can choose to have such apps disabled automatically; that is off by default.
- The availability sweep marks an app whose installed version no longer appears in a listing its source returned without error: "Version withdrawn by the publisher". A withdrawn version is never installed from a stale listing or the artifact cache.
- The alert text says what the signal means: Nextcloud revokes a certificate to withdraw an app it knows to be compromised or malicious. For Nextcloud apps that is the known-malicious feed; no public malware feed lists Nextcloud apps.

## Scope

In scope: the certificate recheck, the newer revocation list with its signature check, the badge, the notification, the opt-in disable, the withdrawn marker and its install guard, tests.

Out of scope:
- Malicious libraries bundled inside an app. `advisories-bundled-libraries` specifies matching bundled libraries against OSV, whose data includes the OpenSSF malicious packages.
- Forge releases, which carry no Nextcloud certificate. Their integrity rests on the recorded SHA-256 (`external-sources`).
- Uninstalling anything. Disabling is the strongest automatic action.

## Impact

- New: `lib/Service/Safety/RevocationList.php`, `lib/Service/Safety/InstalledCertificateCheck.php`, `lib/BackgroundJob/CertificateRecheckJob.php`.
- Changed: `lib/Service/SelectedReleaseInstallerService.php` (`verifyCertificate` reads the list from `RevocationList`), `lib/Service/Availability/AvailabilityService.php` (withdrawn marker, from `inventory-pending-updates`), `lib/Service/InstallerService.php` (withdrawn guard), `lib/Notification/Notifier.php` (`certificate_revoked`), `lib/Service/Settings/InstanceSettings.php` (list address, auto-disable switch), `src/App.vue` (badges), `appinfo/info.xml`, `l10n`.
- New capability spec `withdrawn-and-revoked`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. The fetched list is kept in app data folder `codesigning`, and the settings in app config keys `safety.crl_url` and `safety.disable_revoked`; all are inert without the code.
