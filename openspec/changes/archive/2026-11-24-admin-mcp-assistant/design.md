# Design: admin-mcp-assistant

Read against `development` at 02e1050 (2026-09-27), and against the OpenRegister `development` checkout in the local workspace (94e8c29e42) for the MCP contract.

## Context

- Hydra ADR-034 and ADR-035 (`hydra/openspec/architecture/adr-035-mcp-per-app-coverage.md`) make MCP coverage a fleet expectation: an app with an actionable surface ships `OCA\{Namespace}\Mcp\{Namespace}ToolProvider` implementing `OCA\OpenRegister\Mcp\IMcpToolProvider`, or records an opt-out.
- OpenRegister discovers providers per installed app by probing `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}` and then the class `OCA\{Ucfirst appId}\Mcp\{Ucfirst appId}ToolProvider` (`openregister lib/AppInfo/Application.php:4732-4757`, `buildMcpProviderCandidates()`), with the namespace from `info.xml` as a third candidate. For Versioniq that is `OCA\Versioniq\Mcp\VersioniqToolProvider`, so no registration code is needed.
- The interface (`openregister lib/Mcp/IMcpToolProvider.php:56-110`) has `getAppId()`, `getTools()` returning descriptors `{id, name, description, inputSchema, scope?, readOnlyHint?, destructiveHint?, idempotentHint?}`, and `invokeTool(string $toolId, array $arguments): array`. Tool ids must start with `versioniq.`. The runtime passes the current user's session unchanged, and the provider must check authorisation itself.
- Versioniq has no OpenRegister dependency (`openspec/config.yaml`), and every endpoint checks `isAdmin()` (`lib/Controller/ApiController.php:1386`). CI already installs OpenRegister as an additional app (`.github/workflows/code-quality.yml:154`).
- The data the tools need is already served: `getInstalledApps()` (`lib/Service/InstallerService.php:89`), `AdvisoryResultStore::read()` (`lib/Service/Advisory/AdvisoryResultStore.php:86`), `getAppVersions()` (line 185), and the availability snapshot of `inventory-pending-updates`.

## Goals and non-goals

Goals: an assistant can answer "what is installed, what is affected, what should I move to" from Versioniq's own data, for admins only.

Non-goals: write tools, a separate MCP server, assigning advisories.

## Decisions

### D1. Discovered by name, loaded only with OpenRegister

`lib/Mcp/VersioniqToolProvider.php` implements the interface and is never referenced from `Application::register()`. PHP loads it only when OpenRegister's discovery asks the container for it, and by then OpenRegister's interface exists. A psalm stub under `tests/stubs/` gives static analysis the interface.

Alternative considered: register the provider under the alias in `register()` behind `interface_exists()`. Rejected: Nextcloud registers each app's autoloader in the same loop that calls its `register()`, so the check depends on app order; discovery by class name has no such race.

### D2. Five read-only tools

| Tool | Input | Returns |
|---|---|---|
| `versioniq.listApps` | none | id, name, installed version, state, source |
| `versioniq.listAdvisories` | optional `severity` | the stored advisory results and when they were checked |
| `versioniq.explainAdvisory` | `appId` | that app's advisories with id, severity, summary, affected range, the recommended version, and why none exists when none does |
| `versioniq.listPendingUpdates` | none | the availability snapshot and its time |
| `versioniq.listVersions` | `appId` | the available versions with server compatibility, from the bound source |

Every descriptor carries `readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`. The stored snapshots are read, not recomputed, so a tool call never runs the sweep that timed out in issue #160; `listVersions` is the only live call and lists one app.

### D3. Admin only

`invokeTool()` checks `IGroupManager::isAdmin()` for the session user first, and answers `{error: "Versioniq is available to administrators only."}` otherwise, before reading anything. `getTools()` returns an empty list for a non-admin, so the assistant never offers the tools to them.

### D4. Recording the coverage

`openspec/config.yaml` gets an `mcp-coverage` note naming the provider and the five tools, which is where ADR-035 looks for it in an app without `project.md`.

## Risks and trade-offs

- [An assistant leaks advisory data to a non-admin] → D3 checks admin on every call and hides the tools from non-admins.
- [An assistant overstates what a tool said] → every result carries the snapshot time, and `explainAdvisory` returns the advisory ids so the admin can check them on the Advisories tab.
- [OpenRegister changes its interface] → the stub pins the shape psalm checks, and a unit test asserts the descriptor keys.

## Migration

None. Rollback: revert.
