---
kind: code
---

# Proposal: autoupdate-security-first

## Why

Versioniq knows when an installed version is affected by a published advisory, and it knows how to update an app at night. The two never meet. An admin who wants "install security fixes automatically, nothing else" cannot say so: the policy levels are `patch`, `minor` and `all`, which count version steps and ignore advisories. And an admin who must show a tender how patches are handled has nothing to show: each app has a level with who set it and when, but no written policy per kind of patch, and nothing that puts security fixes first.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq) and covers two rows.

| Row | Rating now | What is missing |
|---|---|---|
| `aut-security-only` | no | Candidate selection knows only semver levels (`CandidateSelector.php:46`) and never reads the advisory snapshot. There is no security-only level. |
| `aud-patch-policy` | partial, built | The built half: a per-app level with `setBy` and `setAt` (`Policy.php:36`), chosen on the Apps tab. The missing half: a written policy per kind of patch, deadlines per advisory severity, and security fixes handled first. |

The capability spec `security-advisory-correlation` says the system MUST NOT auto-update in response to an advisory. That rule protects the admin from surprise changes, and it stays: the advisory check itself never changes a version. What this change adds is a choice the admin makes in advance. This change MODIFIES that requirement to say so, and keeps its core: no automatic change without a policy the admin set, and never an automatic unpin.

### Demand

- `aud-patch-policy`: tender, https://www.tenderned.nl/aankondigingen/overzicht/371718. Provincie Utrecht, online energieloket, requirement 75801; also Omgevingsdienst IJsselland requirement 107642. A patch management policy that states how each type of patch is handled, security patches first.
- `aut-security-only`: no demand row in the matrix.

### Competitors rated yes (evidence quoted from the matrix)

- `aut-security-only`, two rated yes. Renovate: "lib/config/presets/internal/security.preset.ts:142-155 security:only-security-updates disables all updates except vulnerability fixes." Dependabot: "Core: security-only jobs (updater/lib/dependabot/job.rb:305, updater/lib/dependabot/updater/operations/create_security_update_pull_request.rb); closed service: security updates switch on independently of version updates (https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-security-updates)."
- `aud-patch-policy`, Renovate rated yes: "The update policy is written as versioned config: vulnerabilityAlerts (lib/config/options/index.ts:2428) for security fixes, packageRules per update type, schedules and automerge, reviewable in the repository." No other competitor is rated yes.

## What changes

- A fifth policy level, `security`. An app on it gets an automatic update only when the last advisory check says its installed version is affected. The job then installs the version the advisory check recommends, in the next window, through the standard installer. A fix that needs a new major version is not installed; the page says why.
- A patch policy on the Settings tab. Per advisory severity (critical, high, medium, low, unknown) an admin sets a deadline in days and how the fix is handled: at the next window, with the normal schedule, or by hand. Per kind of regular update (patch, minor, major) an admin sets automatic or by hand. A free text statement goes with it.
- Security fixes go first. In a run, affected apps install before any other update. When the policy says "next window" for that severity, the fix does not wait for its kind's day and does not count against a run limit.
- A regular kind set to "by hand" is never installed automatically, whatever an app's level says.
- The page shows the policy as plain sentences, with who changed it and when, and offers it as a text download. Every change writes an audit entry.
- The advisory requirement is reworded: an advisory never changes a version by itself; a policy the admin set in advance may.

## Scope

In scope: the `security` level, the security candidate rule, the security-first order, the patch policy (store, API, page, statement, audit entry), the MODIFIED advisory requirement, tests.

Out of scope:
- A default level for apps without a policy. `autoupdate-default-and-config-file` specifies it; with it, an admin can make `security` the default for every app.
- Kind schedules and run limits themselves. `autoupdate-schedules` and `autoupdate-run-batching` specify them; this change only says security fixes are not held by them.
- Reports of how fast advisories were fixed against the deadlines. `audit-security-reports` specifies them and reads the deadlines stored here.
- Dismissing an advisory. `advisories-triage` specifies it; a dismissed advisory does not trigger a security install.

## Impact

- New: `lib/Service/Policy/PatchPolicy.php`, `lib/Service/Policy/PatchPolicyStore.php`, `lib/Service/AutoUpdate/SecurityCandidate.php`, `src/components/PatchPolicyPanel.vue`.
- Changed: `lib/Service/Policy/Policy.php` (level `security`), `lib/BackgroundJob/AutoUpdateJob.php` (security first, patch policy handling), `lib/Controller/ApiController.php` (level validation, new `GET` and `PUT /api/patch-policy`), `lib/Service/Audit/AuditLogger.php` (operation `patch_policy`), `src/components/PolicySelector.vue`, `src/components/AutoUpdateOverview.vue`, `src/components/InstanceSettingsPanel.vue` (mounts the patch policy), `l10n/en.json`, `l10n/nl.json`.
- Capability specs: `auto-update-policies` MODIFIED "Per-app update policy [MVP]" and two ADDED requirements; `security-advisory-correlation` MODIFIED "The admin is notified and stays in control".

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one and can read the patch policy.

## Rollback

Revert the change. An app stored with level `security` then fails `Policy::isValidLevel()`, and `PolicyStore` already logs such a value and treats it as `none` (`lib/Service/Policy/PolicyStore.php:128-137`), so it stops updating rather than breaking. The patch policy lives in app config key `patch_policy`; nothing else reads it. Before reverting, an admin can list `security` apps with `occ config:app:get` and reset them.
