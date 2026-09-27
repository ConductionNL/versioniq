# assistant-tools Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [admin-mcp-assistant](../../)

## Purpose

An admin asks the fleet's assistant about installed versions, advisories and pending updates, and gets the answer Versioniq's own pages give.

## ADDED Requirements

### Requirement: Versioniq publishes read-only assistant tools for admins

When OpenRegister is installed, Versioniq MUST publish an MCP tool provider that OpenRegister's discovery finds by class name, with the tools `versioniq.listApps`, `versioniq.listAdvisories`, `versioniq.explainAdvisory`, `versioniq.listPendingUpdates` and `versioniq.listVersions`. Every tool MUST be read-only and marked so. Every call MUST check that the caller is an admin before reading anything, and the tools MUST NOT be listed for a non-admin. Results built from a stored snapshot MUST carry the snapshot time. Without OpenRegister, Versioniq MUST load no MCP code.

#### Scenario: An admin asks which apps are affected

- **GIVEN** OpenRegister is installed and the last advisory sweep stored an advisory for `openregister` 2.3.0 with 2.3.4 recommended
- **WHEN** an admin asks the assistant which installed apps are affected and the assistant calls `versioniq.explainAdvisory` for `openregister`
- **THEN** the tool MUST return the advisory id, its severity, 2.3.4 as the recommended version, and the time of the sweep

#### Scenario: A non-admin gets nothing

- **GIVEN** a user who is not an admin talks to the same assistant
- **WHEN** the assistant lists its tools or calls `versioniq.listApps`
- **THEN** no Versioniq tool MUST be listed, and the call MUST return the admin-only error without reading any data
