# 3. External release checksums are pinned on first use

- Status: Accepted
- Date: 2026-07-23

## Context

A sibling `.sha256` asset sits next to the release artifact, so a maintainer who rewrites a release rewrites both. It proves the download arrived intact, not that the bytes are the ones installed before. Reinstalling or rolling back to a version later would accept whatever the source serves at that moment.

## Decision

- Use trust on first use: after a successful install from an external source, record the SHA-256 of the installed artifact per version.
- Store the recorded digests inside the app's source binding (`SourceBinding`), not under a separate key, so they share the binding's lifecycle: rebinding to a different source drops them, rebinding to the same source keeps them.
- On a later install of a recorded version, fail closed before extraction when the downloaded bytes do not match the recorded digest.
- Record a digest only after the whole install succeeds, so a failed install cannot poison the record.
- Allow an explicit, password-confirmed `acceptNewSha` override that replaces the recorded digest and is audited.

## Consequences

- A release rewritten upstream cannot silently replace a version that was installed before.
- The first install is trusted exactly as much as before; a compromised first download is out of scope by design.
- A legitimate re-release needs a deliberate override from an administrator.

## Source

`openspec/changes/archive/2026-07-23-add-external-source-sha-pinning/design.md` ("Trust model", "Storage", "Enforcement point"); implemented in `lib/Service/Source/SourceBinding.php` and `lib/Service/ExternalReleaseInstallerService.php`.
