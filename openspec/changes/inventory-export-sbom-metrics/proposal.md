---
kind: code
---

# Proposal: inventory-export-sbom-metrics

## Why

Auditors and procurement ask for a software bill of materials (SBOM): a machine-readable list of every component and its version, in CycloneDX or SPDX. Monitoring teams ask for the same list as metrics they can scrape. Versioniq knows every installed app, its version and where it came from, and hands none of it out except one app at a time through `occ versioniq:versions --json`.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers three rows that share one inventory serializer.

| Row | Rating now | What is missing |
|---|---|---|
| `inv-export-inventory` | no | No export of the installed components and their versions, and no SBOM. |
| `inv-sbom-per-update` | no | No fresh SBOM after each update. |
| `inv-metrics-endpoint` | no | No monitoring metric for installed app versions, shipped apps, or apps that are behind. |

### Demand

- `inv-sbom-per-update`: tender, https://www.tenderned.nl/aankondigingen/overzicht/412432. UWV, ICT Werkplekhardware en accessoires, requirement 185427 (also 185121, 185307, 76785): an SBOM delivered with each update or new version, machine-readable, CycloneDX or SPDX.
- `inv-metrics-endpoint`: feature request, https://github.com/nextcloud/server/issues/62895. "OpenMetrics: add a shipped label to the app inventory metric", opened 2026-08-04 and open. It builds on the OpenMetrics exporter in Nextcloud 33 (server PR 57165).
- `inv-export-inventory`: no demand row; two competitors rated yes, and the row is in the core area.

### Competitors rated yes (evidence quoted from the matrix)

- `inv-export-inventory`, Dependabot rated yes: "the dependency graph exports an SPDX SBOM from the UI and REST API (https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/establish-provenance-and-integrity/export-dependencies-as-sbom)". OSV-Scanner rated yes: "Outputs SPDX 2.3 and CycloneDX 1.4 to 1.7 with all-packages (internal/reporter/format.go:10, docs/output.md:427-431, docs/output.md:555-559)".
- `inv-sbom-per-update` and `inv-metrics-endpoint`: no competitor rated yes. Dependabot and OSV-Scanner are partial on the SBOM per update ("it is not produced or delivered automatically per update"). Nextcloud is partial on metrics: "lib/private/OpenMetrics/Exporters/AppEnabled.php:52-53 emits app_enabled with app_id and version labels for every installed app. No shipped label and no label for an available update".

## What changes

- One inventory: the server and every installed app, with version, licence, where it came from, and the recorded SHA-256 when there is one.
- Export it as CycloneDX 1.5 JSON or SPDX 2.3 JSON, from a button on the History tab, from `GET /api/sbom?format=`, and from `occ versioniq:sbom --format=`.
- After every successful real install, Versioniq writes a fresh SBOM and keeps it next to the history row, so each update has the SBOM of the state it produced.
- On Nextcloud 33 and later, Versioniq adds an OpenMetrics family `versioniq_app_version` with the labels `app_id`, `version`, `shipped`, `source` and `behind`.

## Scope

In scope: the inventory builder, the two SBOM formats, the per-update copy and its retention, the endpoint, the command, the metrics family, tests.

Out of scope:
- The libraries bundled inside each app. `advisories-bundled-libraries` specifies reading them; the SBOM adds them as nested components once that lands.
- Signing the SBOM. No row or demand asks for it yet.
- An SBOM of software outside the instance (matrix row `adv-sbom-import`, decided-no).

## Impact

- New: `lib/Service/Inventory/InventoryBuilder.php`, `lib/Service/Inventory/SbomWriter.php` (CycloneDX and SPDX), `lib/Service/Inventory/SbomArchive.php` (app data), `lib/Command/ExportSbom.php`, `lib/OpenMetrics/AppVersionMetricFamily.php`.
- Changed: `lib/Service/InstallerService.php` (after a successful real install), `lib/Controller/ApiController.php` (two routes), `appinfo/info.xml` (command, `<openmetrics>` entry), `src/components/HistoryPanel.vue` (export button and per-row download), `l10n`.
- New capability spec `inventory-export`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. The per-update SBOMs live in the app data folder `sbom`, which can be deleted; the metrics family disappears with the `<openmetrics>` entry.
