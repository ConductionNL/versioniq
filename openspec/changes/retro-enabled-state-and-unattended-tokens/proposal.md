---
kind: config
---

# Proposal: retro-enabled-state-and-unattended-tokens

## Why

Two rows of the competitor parity matrix (`openspec/parity/capabilities.json`, versioniq) are built, and their boards draw them, but no requirement says them.

| Row | Rating now | What is missing |
|---|---|---|
| `ins-keep-enabled-state` | yes | No requirement says an app keeps its enabled or disabled state across a version change. VqInstalleren draws it. |
| `aut-unattended-private` | yes | pat-management specifies `sharedWithAdmins` but not that background jobs use only shared tokens. VqTokens draws it. |

## What changes

- `version-management` "Install Specific Version": a SHALL that the enabled or disabled state, group limits included, survives a version change, with two scenarios.
- `pat-management`: a new requirement that background jobs use only tokens shared with all admins.
- `capabilities.json` records `built.requirement` for these two rows and for ten built rows whose requirement exists but was never named.

No code changes. The behaviour is built: `lib/Service/SelectedReleaseInstallerService.php:327`, `lib/Service/ExternalReleaseInstallerService.php:157`, `lib/Service/Installer/InstallFinalizer.php:150` and `lib/Service/Pat/PatResolver.php:40-90`.

## Impact

- MODIFIED requirement in `version-management`, ADDED requirement in `pat-management`.
- `openspec/parity/capabilities.json`: 12 rows gain `built.requirement`.

### MCP coverage

No MCP surface in this change.

## Rollback

Revert the change. Nothing runs from it.
