# admin-mcp-assistant tasks

## 1. Provider

- [ ] 1.1 Add the psalm stub for `OCA\OpenRegister\Mcp\IMcpToolProvider` and `lib/Mcp/VersioniqToolProvider.php` with `getAppId()` and the five descriptors of design D2. Verify: `tests/unit/Mcp/VersioniqToolProviderTest.php` asserts every id starts with `versioniq.` and every descriptor is read-only.
- [ ] 1.2 Implement `invokeTool()` with the admin check first. Verify: unit tests that a non-admin gets the error and no service is called, and that `getTools()` is empty for a non-admin.
- [ ] 1.3 Implement each tool over the existing services and snapshots. Verify: one unit test per tool with stubbed services, including `explainAdvisory` for an app with no recommended version.

## 2. Wiring and record

- [ ] 2.1 Confirm OpenRegister's discovery picks the provider up on an instance with OpenRegister installed. Verify: an integration test in CI (OpenRegister is already an additional app) resolves `OCA\Versioniq\Mcp\VersioniqToolProvider` through OpenRegister's `McpToolsService` and lists five tools.
- [ ] 2.2 Add the `mcp-coverage` note to `openspec/config.yaml`.

## 3. Close

- [ ] 3.1 Set the matrix row `adm-ai-assistant` to `built` with evidence lines, then archive this change.
