---
kind: code
---

# Proposal: pinning-ranges-and-skips

## Why

An admin who sets an app to "patch updates only" expects that to hold. Today it holds for the nightly job and nowhere else: a colleague can pick 3.0.0 in the version picker and install it. And when one release is known to be bad, there is no way to say "not this one, but the next one is fine". The admin has to pin the app, which also blocks the fix that follows.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers two rows about how narrowly an admin can hold an app.

| Row | Rating now | What is missing |
|---|---|---|
| `pin-range` | partial, built | The missing half: the per-app level (Patch, Minor, All) limits the nightly job only. Manual installs through Versioniq are not held to it. |
| `pin-ignore-version` | no | No way to skip one chosen version. The attempt ledger never retries a version the job already tried, but that is automatic, not an admin choice (`lib/Service/AutoUpdate/AttemptLedger.php:49`). |

### Demand

No demand row. Three competitors rate `pin-range` yes, two rate `pin-ignore-version` yes.

### Competitors rated yes (evidence quoted from the matrix)

- `pin-range`: Renovate, "lib/config/options/index.ts:1859 allowedVersions restricts to a range, and matchUpdateTypes (lib/config/options/index.ts:1798) with enabled:false limits a package to patch releases". Dependabot, "ignore update-types (semver-major, minor, patch) and allow update-types limit updates to a range ... ignore.versions takes ranges (https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)". OSV-Scanner, "upgrade-config per package or globally at major, minor, patch or none (docs/guided-remediation.md:741-756)".
- `pin-ignore-version`: Renovate, "Closing a PR ignores that version and later versions still arrive (lib/workers/repository/update/branch/handle-existing.ts:13-30); allowedVersions accepts a negated regex to skip one version". Dependabot, "ignore.versions skips specific versions while later ones are still proposed ... @dependabot ignore this patch version does the same from a pull request (https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-pull-request-comment-commands)".

## What changes

- A policy gets a switch, "Also hold manual installs to this level". With it on, Versioniq's own install path refuses a version outside the level with category `outOfRange`, the way it refuses to overwrite a pin. The admin can go ahead with an explicit, audited override.
- An admin can skip one version of an app from the version picker or from the update badge, with an optional reason. A skipped version is never chosen by the nightly job, never shown as the pending update, and marked "Skipped" in the picker with an Unskip action. Later versions are offered as usual. Installing a skipped version by hand stays possible after a confirmation.
- Both actions are audited.

## Scope

In scope: the policy switch, the range guard and its override, the skip list, its API and page actions, the audit operations, tests.

Out of scope:
- Holding Nextcloud's own updater to the range. That is impossible without core changes, a recorded non-goal of `2026-07-23-add-version-pinning` (row `pin-enforce-platform`, decided-no). The switch's help text says so.
- Ranges written as expressions (`>=2.3 <2.5`). Patch and Minor cover the rows; an expression field can come later if demand asks.
- A pin expiry (row `pin-expiry`, deferred).

## Impact

- New: `lib/Service/Pin/SkipList.php`.
- Changed: `lib/Service/Policy/Policy.php` and `PolicyStore.php` (`holdManual`), `lib/Service/InstallerService.php` (range guard and skip warning), `lib/Service/AutoUpdate/CandidateSelector.php` (skipped versions), `lib/Service/Availability/AvailabilityService.php` from `inventory-pending-updates` (skipped versions), `lib/Controller/ApiController.php` (skip routes, the override flag), `lib/Service/Audit/AuditLogger.php` (operations `skip_version`, `unskip_version`, `range_override`), `src/components/PolicySelector.vue`, `src/App.vue` (picker and badge), `l10n`.
- ADDED requirements in `auto-update-policies` and `version-pinning`.

### MCP coverage

No MCP surface in this change: both actions change what gets installed and stay with the admin.

## Rollback

Revert the change. The switch is an extra key in the stored policy JSON that older code ignores (`Policy::fromArray()` reads only its three keys); the skip lists live in app config keys `skip.<appId>`.
