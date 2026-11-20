# 2. External release sources get their own installer

- Status: Accepted
- Date: 2026-05-02

## Context

App Store installs go through `SelectedReleaseInstallerService`, which verifies the app certificate against the Nextcloud code-signing chain. Releases from external sources such as GitHub have no Nextcloud-issued certificate, so they cannot pass that check. Adding an "if external, skip the certificate" branch to the App Store installer would make its most security-critical method harder to reason about and could let a later refactor bypass the certificate check for App Store installs.

## Decision

- Install external releases through a parallel `ExternalReleaseInstallerService` and leave the App Store path unchanged. The two share the extraction, copy and finalize steps rather than duplicating them.
- Resolve versions through a `SourceRegistry` and a per-app source binding: an app bound to an external source is listed from that source, an unbound app falls back to the App Store.
- Gate every external install with a trusted-source allowlist (`TrustedSourceList`) before anything is downloaded, then check that the archive's `appinfo/info.xml` declares the requested app id and version, and verify a sibling `.sha256` asset when the release has one.

## Consequences

- The App Store trust model (certificate and signature) and the external trust model (allowlist, id and version match, optional checksum) stay in separate classes.
- External installs are weaker than signed App Store installs; the allowlist limits which repositories can be installed from, and installs still need password confirmation.
- New source kinds plug in behind `SourceInterface` without touching the App Store installer.

## Source

`openspec/changes/archive/2026-05-02-add-external-source-installs/design.md` ("Architecture overview", "Trusted-source allowlist", "Why parallel installer instead of extending SelectedReleaseInstallerService", "Integrity checks for external artifacts"); implemented in `lib/Service/ExternalReleaseInstallerService.php`, `lib/Service/SelectedReleaseInstallerService.php` and `lib/Service/Source/`.
