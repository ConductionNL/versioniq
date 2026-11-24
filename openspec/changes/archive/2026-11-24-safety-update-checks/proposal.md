---
kind: code
---

# Proposal: safety-update-checks

## Why

Teams that update through Renovate or Dependabot get an update only after their own tests pass. A Versioniq admin gets a dry run that downloads and verifies the package, and an installer that restores the old files when the install fails. There is nothing in between: no place to plug in a smoke test, a staging verdict from a CI pipeline, or any other check, and the nightly job installs whatever passes the installer's own verification. The dry run of an App Store package also skips the server-compatibility and dependency check that a real install runs, so a dry run can pass and the install still fail.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers two rows that share one set of checks.

| Row | Rating now | What is missing |
|---|---|---|
| `saf-test-before-apply` | partial, built | The missing half: the dry run runs no tests, and for App Store packages it skips the compatibility and dependency check the real install runs. |
| `aut-automerge` | partial, built | The missing half: automatic installs go through the verifying installer, but an admin cannot attach a test or health gate that must pass first. |

### Demand

No demand row for either. Both are partial and built, and each has two competitors rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- `saf-test-before-apply`, Renovate rated yes: "Updates arrive as PRs that run the repository's CI; automerge only merges when status checks pass (lib/config/options/index.ts:2376, ignoreTests lib/config/options/index.ts:2413), and a 'Pending Status Checks' section shows waiting ones (dependency-dashboard.ts:562)." Dependabot rated yes: "every update arrives as a pull request on which the repository's CI runs before merge ... compatibility scores add results from other repositories."
- `aut-automerge`, Renovate rated yes: "lib/config/options/index.ts:2376 automerge with automergeType (lib/config/options/index.ts:2383) and platformAutomerge (lib/config/options/index.ts:3466) merges once status checks pass." Dependabot rated yes: "GitHub auto-merge plus the fetch-metadata action merge Dependabot pull requests once required checks pass ... The merge is the platform's, driven by a workflow."

## What changes

- A dry run of an App Store package runs the same `info.xml` validation as a real install: app id, server compatibility and dependencies, PHP version and extensions included. A dry run that passes now means the installer's own checks will pass.
- An admin adds verdict checks on the Settings tab: an https address, with the app id and versions filled in, that must answer 2xx. A CI pipeline that tested the version on a staging server can serve that verdict.
- A server operator adds command checks in `config.php`: a program and its arguments, run with the app id, the version and the path of the unpacked package. Exit code 0 passes. They cannot be set from the web page.
- Each check applies to every app or to listed apps. Every dry run, from the page or `occ versioniq:install --dry-run`, runs the matching checks and reports each result.
- When any check matches an app, the nightly job runs a dry run with its checks before it installs, and installs only when all pass. A failed check blocks that version like a failed install: it is recorded, notified with the check's name and output, and can be retried from the page.

## Scope

In scope: full validation in the signed dry run, both check kinds, their settings, running them in dry runs and in the nightly job, the report, tests.

Out of scope:
- Checks after an install, with an automatic rollback. The archived `add-auto-update-policies` proposal keeps automatic rollback out, because database migrations only run forward, and that reason still holds. `install-bulk-rollback` gives an admin the manual path.
- A version that must first run on a test instance. `install-multi-instance-promotion` specifies staged promotion with a waiting period.
- Running checks for a manual install that is not a dry run. The admin who installs by hand chose the version; the dry run is one toggle away.

## Impact

- New: `lib/Service/Checks/UpdateCheck.php`, `lib/Service/Checks/CheckStore.php`, `lib/Service/Checks/CheckRunner.php`, `lib/Service/Checks/HttpVerdictCheck.php`, `lib/Service/Checks/CommandCheck.php`, `src/components/UpdateChecksPanel.vue`.
- Changed: `lib/Service/SelectedReleaseInstallerService.php` (validation in the dry run, checks before cleanup), `lib/Service/ExternalReleaseInstallerService.php` (checks before cleanup), `lib/Service/InstallerService.php` (check results in the payload), `lib/BackgroundJob/AutoUpdateJob.php` (the gate), `lib/Service/Installer/FailureClassifier.php` (category `checks_failed`), `lib/Command/InstallVersion.php` (results and an exit code), `lib/Controller/SettingsController.php` (verdict checks), `src/components/InstallResultNotices.vue`, `src/components/InstanceSettingsPanel.vue`, `l10n/en` and `l10n/nl`.
- New capability spec `update-checks`; ADDED requirement in `auto-update-policies`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; a dry run with checks is a read-only action it can offer.

## Rollback

Revert the change. Verdict checks live in app config key `update_checks`; command checks in the operator's own `config.php` key `versioniq.update_checks`, which Nextcloud ignores without this code. Versions blocked by a failed check stay listed as blocked, with the existing Retry.
