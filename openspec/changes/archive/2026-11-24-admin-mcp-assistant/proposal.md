---
kind: code
---

# Proposal: admin-mcp-assistant

## Why

An admin who wants to know "which of my apps are affected by an advisory, and what should I update to" reads the Advisories tab, opens the app, and reads the version list. The same question asked of an assistant should get the same answer from the same data. OSV-Scanner and GitHub already expose their vulnerability data as tools an assistant can call. Versioniq exposes nothing, and hydra ADR-035 expects every Conduction app with an actionable surface to publish at least one tool or record why not.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `adm-ai-assistant` | no | No MCP server or assistant integration under `lib/` or `src/`. |

### Demand

- Competitor changelog, https://github.com/google/osv-scanner/pull/2256 (OSV-Scanner's MCP server). Two competitors are rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- Dependabot: "The official GitHub MCP server has a dependabot toolset with list_dependabot_alerts and get_dependabot_alert (https://github.com/github/github-mcp-server), and alerts can be assigned to AI agents that propose a fix (https://github.blog/changelog/2026-04-07-dependabot-alerts-are-now-assignable-to-ai-agents-for-remediation/)".
- OSV-Scanner: "Experimental osv-scanner experimental-mcp since v2.2.4 (2025-10-29) exposes scan_vulnerable_dependencies, get_vulnerability_details and ignore_vulnerability tools (cmd/osv-scanner/mcp/command.go:60-86, CHANGELOG.md:230)".

## What changes

- Versioniq ships `OCA\Versioniq\Mcp\VersioniqToolProvider`, the class name OpenRegister's MCP discovery probes for every app. When OpenRegister is installed, the fleet's assistant can call Versioniq's tools; without OpenRegister nothing loads and nothing changes.
- Five read-only tools: list the installed apps with their versions, read the stored advisory results, explain one app's advisories with the recommended version, list pending updates, and list one app's available versions.
- Every tool checks that the caller is an admin, because Versioniq is admin-only. No tool installs, pins, binds or changes anything; installing stays a human action behind a password.

## Scope

In scope: the provider, the five tools, the admin check, the stub for static analysis, tests.

Out of scope:
- A write tool (install, pin, dismiss). Versioniq's installs require password confirmation, which an assistant cannot give on the admin's behalf.
- A standalone MCP server process. The fleet's assistant reaches app tools through OpenRegister (ADR-034, ADR-035).
- Assigning an advisory to an assistant. `advisories-triage` specifies assignment to a person.

## Impact

- New: `lib/Mcp/VersioniqToolProvider.php`, `tests/stubs/OpenRegister/Mcp/IMcpToolProvider.php` (psalm stub).
- Reads: `InstallerService::getInstalledApps()`, `AdvisoryResultStore::read()`, `InstallerService::getAppVersions()`, and the availability snapshot of `inventory-pending-updates`.
- `openspec/config.yaml` gains an `mcp-coverage` note; new capability spec `assistant-tools`.

### MCP coverage

Adds tools: `versioniq.listApps`, `versioniq.listAdvisories`, `versioniq.explainAdvisory`, `versioniq.listPendingUpdates`, `versioniq.listVersions`.

## Rollback

Revert the change. With the class gone, OpenRegister's discovery finds no Versioniq provider and the assistant loses the tools; nothing is stored.
