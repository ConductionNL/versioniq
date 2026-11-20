---
kind: config
---

# Proposal: write-guards-and-advisory-alerts

## Why

Three built rows of the competitor parity matrix (`openspec/parity/capabilities.json`, versioniq) point at a requirement that says only part of what the row claims. The coverage audit of 12 Nov 2026 found them.

| Row | What is missing |
|---|---|
| `adm-password-confirm` | No requirement says every write action asks for the admin password. "Pin an installed app to its current version" covers pinning only. |
| `saf-protect-core` | "List Installed Apps" only marks core apps. No requirement says an install of a core app or of Versioniq itself is refused on every path. `cli-commands` says it for occ only. |
| `adv-notify-urgent` | "The admin is notified and stays in control" says a new advisory notifies at the scheduled refresh. It does not say a critical advisory skips the weekly digest. |

## What changes

- `version-management`: two new requirements. Every write route asks for the admin password. Core apps and Versioniq itself are refused on the API, occ and the automatic update job.
- `security-advisory-correlation` "The admin is notified and stays in control": a scenario for a critical advisory that reaches the admin at the next sweep, not in the digest.
- `capabilities.json`: the three rows cite these requirements.

No code changes. The behaviour is built:

- `#[PasswordConfirmationRequired]` on every POST, PUT, PATCH and DELETE route of `lib/Controller/ApiController.php`, `lib/Controller/ForgeController.php` and `lib/Controller/SettingsController.php`.
- `InstallerService::isManageableApp()` (`lib/Service/InstallerService.php:1387`), checked by the version list, the install route and `lib/Command/InstallVersion.php:108`.
- `AdvisoryNotifier::notifyNewAdvisories()` (`lib/Service/Advisory/AdvisoryNotifier.php:53`), called on every sweep by `lib/BackgroundJob/AdvisoryRefreshJob.php:100`. The sweep runs every 1 to 24 hours (`AdvisorySettingsStore`).

## Impact

- ADDED requirements in `version-management`, MODIFIED requirement in `security-advisory-correlation`.
- `openspec/parity/capabilities.json`: three rows change their `built.requirement`.

### MCP coverage

No MCP surface in this change.

## Rollback

Revert the change. Nothing runs from it.
