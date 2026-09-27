---
kind: code
---

# Proposal: autoupdate-default-and-config-file

## Why

An instance with sixty apps needs sixty clicks before automatic updates do anything, because an app without its own policy means "never". And the policies live only in the database, so an admin who runs test, acceptance and production cannot keep them in the same place as the rest of their configuration, review a change to them, or roll one back.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers two rows about where policies come from.

| Row | Rating now | What is missing |
|---|---|---|
| `aut-global-default` | no | No instance-wide default level. An app with no stored policy is treated as none (`lib/Service/Policy/PolicyStore.php:58-60`), so every app is set one by one. |
| `aut-config-as-code` | no | Policies live in app config keys `policy.{appId}`; there is no file to export, import or keep under version control. |

### Demand

No demand row. `aut-global-default` is rated yes by three competitors, `aut-config-as-code` by two.

### Competitors rated yes (evidence quoted from the matrix)

- `aut-global-default`: Renovate, "Top-level config, presets such as config:recommended (lib/config/presets/internal/config.preset.ts) and globalExtends (lib/config/options/index.ts:454) set one default for every dependency". Dependabot, "options on an updates entry apply to every dependency of that ecosystem and directory (https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)". Easy Updates Manager, "plugin and theme auto-updates on, off, WordPress default or per item, plus quick actions Auto update everything and Disable auto updates (includes/MPSUM_Admin_Ajax.php:218-240)".
- `aut-config-as-code`: Renovate, "renovate.json and other config files in the repository (lib/config/options/index.ts:296 configFileNames), validated by renovate-config-validator". Dependabot, ".github/dependabot.yml, versioned in the repository (https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)".

The archived `2026-07-23-add-auto-update-policies` spec says an absent policy means none. That was the safe start, not a non-goal: the default stays none until an admin changes it.

## What changes

- The Automatic updates settings get a default level: none (the current behaviour), patch, minor or all. Every manageable app without its own policy follows it. Core apps and pinned apps stay out, as today.
- Each card shows whether its level is its own or the default, and the admin can reset an app to the default.
- `occ versioniq:policies:export` writes the kill switch, window, default and every per-app policy to a JSON file in a documented shape. `occ versioniq:policies:import <file>` applies one, with `--dry-run` to show the difference first and `--prune` to remove per-app policies the file does not name.
- An admin who wants the file to be the only source sets `versioniq.policy_file` in `config.php`. The nightly job then reads that file at every run, and the page shows the policies read-only with the file's path, so the database and the file cannot drift apart.

## Scope

In scope: the default level, the effective level on cards and in the API, export and import, the file mode, validation, audit, tests.

Out of scope:
- Sharing presets across instances (row `aut-presets`, deferred). The file travels with the admin's own tooling.
- Per-app windows and schedules. `autoupdate-schedules` specifies them; the file carries them once that lands.

## Impact

- New: `lib/Service/Policy/PolicyFile.php` (shape, validation, read, write), `lib/Command/ExportPolicies.php`, `lib/Command/ImportPolicies.php`.
- Changed: `lib/Service/Policy/PolicyStore.php` (default and effective level), `lib/Service/AutoUpdate/AutoUpdateSettingsStore.php` (default key), `lib/BackgroundJob/AutoUpdateJob.php` (every manageable app, file mode), `lib/Controller/ApiController.php` (`GET /api/policies` returns the default, the effective level and the file mode; writes refused in file mode), `src/components/PolicySelector.vue`, `src/components/AutoUpdateOverview.vue`, `src/App.vue`, `appinfo/info.xml`, `l10n`.
- MODIFIED requirement `Per-app update policy [MVP]` in `auto-update-policies`; ADDED requirements in `auto-update-policies` and `cli-commands`.

### MCP coverage

No MCP surface in this change: policies decide what gets installed and stay with the admin.

## Rollback

Revert the change. The default is one app config key, `auto_update_default_level`; with it gone every app without a policy reads none again, as before.
