---
kind: config
---

# Proposal: platform-properties

## Why

Two rows of the competitor parity matrix (`openspec/parity/capabilities.json`, versioniq, compared 2026-09-26) are built but no spec says them. They are properties of the whole app, not features with a screen.

| Row | Rating now | What is missing |
|---|---|---|
| `adm-self-hosted` | yes | No requirement says Versioniq runs on premises with no SaaS dependency. |
| `adm-free` | yes | No requirement says every feature is free of a paid plan or licence gate. |

## What changes

- A new capability `platform-properties` with two requirements: Versioniq runs inside the Nextcloud instance with every outbound address overridable, and every feature works without a paid plan.
- No code changes. Both rows are built today (`appinfo/info.xml` licence EUPL-1.2, `lib/Service/Settings/InstanceSettings.php` address overrides, `lib/Service/Source/ForgeRegistry.php` forge overrides).

## Scope

In scope: the two requirements and the matrix rows pointing at them.

Out of scope: new override settings. The ones that exist are already specified in `audit-trail` ("Instance settings on a page").

## Impact

- New capability spec `platform-properties`.
- `openspec/parity/capabilities.json`: rows `adm-self-hosted` and `adm-free` cite this change and its requirements.

### MCP coverage

No MCP surface: these are properties, not actions.

## Rollback

Revert the change. Nothing runs from it.
