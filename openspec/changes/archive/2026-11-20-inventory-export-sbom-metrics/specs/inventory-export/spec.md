# inventory-export Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-export-sbom-metrics](../../)

## Purpose

An admin hands auditors and monitoring the list of what runs on the instance, in a standard format, current and after every update.

## ADDED Requirements

### Requirement: An admin exports the inventory as an SBOM

The system MUST export the Nextcloud server and every installed app, each with its version, licence, source and recorded SHA-256 when one exists, as CycloneDX 1.5 JSON or SPDX 2.3 JSON. The export MUST be reachable from the History tab, from `GET /api/sbom?format=cyclonedx|spdx`, and from `occ versioniq:sbom --format=`. Every route MUST be admin-only. A GitHub-bound app MUST carry a `pkg:github` package URL.

#### Scenario: An admin downloads a CycloneDX SBOM

- **GIVEN** the instance runs `openregister` 2.3.0 from the App Store and `hermiq` 1.4.0 bound to `github:ConductionNL/hermiq`
- **WHEN** an admin opens the History tab and picks Export SBOM, CycloneDX
- **THEN** the browser MUST download a CycloneDX 1.5 JSON file that lists the server, `openregister` 2.3.0 and `hermiq` 1.4.0
- **AND** `hermiq` MUST carry the package URL `pkg:github/ConductionNL/hermiq@1.4.0`

#### Scenario: A script writes an SPDX SBOM

- **WHEN** an admin runs `occ versioniq:sbom --format=spdx --output=/srv/sbom.json`
- **THEN** the file MUST be valid SPDX 2.3 JSON and the exit code MUST be 0

### Requirement: Every update leaves an SBOM of the state it produced

After every successful real install, the system MUST write an SBOM of the new state and keep it with that install's history row. A dry run MUST NOT write one. Failing to write it MUST NOT fail or change the install result. The system MUST keep the newest 200 copies unless the admin sets another number.

#### Scenario: An auditor gets the SBOM of one update

- **GIVEN** an admin updated `openregister` from 2.3.0 to 2.4.1 through Versioniq
- **WHEN** the admin opens the History tab and clicks the SBOM link on that install row
- **THEN** the downloaded SBOM MUST list `openregister` 2.4.1

### Requirement: Installed app versions are exposed as OpenMetrics

On Nextcloud 33 and later, the system MUST add the metric family `versioniq_app_version` to the server's OpenMetrics endpoint, with one sample per installed app and the labels `app_id`, `version`, `shipped`, `source` and `behind`. `behind` MUST read `unknown` when no availability sweep has run.

#### Scenario: A monitoring system scrapes app versions

- **GIVEN** a Nextcloud 33 instance with `calendar` shipped and `openregister` 2.3.0 from the App Store
- **WHEN** the monitoring system scrapes `/metrics` with the server's metrics access
- **THEN** it MUST read `nextcloud_versioniq_app_version` samples for `calendar` with `shipped="true"` and for `openregister` with `version="2.3.0"`
