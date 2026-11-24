# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [advisories-ncsc-feed](../../)

## ADDED Requirements

### Requirement: NCSC-NL advisories are matched to the installed server and apps

When an admin switched on NCSC-NL checks (off by default), the advisory sweep MUST read the NCSC-NL CSAF directory incrementally through its `changes.csv`, MUST keep only documents that name a Nextcloud product, and MUST match each such product to the Nextcloud server or an installed app with the same name matching the Nextcloud feed uses, dropping clients and names that resolve to nothing. When the document states a version range, the system MUST check the installed version against it. When it states none, or states `vers:unknown`, the advisory MUST be listed as not stating which versions are affected and MUST NOT mark the installed version affected. An NCSC-NL advisory that shares a CVE id with another advisory for the same app or server MUST be shown and counted once, carrying both ids. The Advisories tab MUST show each NCSC-NL advisory with its tracking id, title, CVE ids, severity from its CVSS scores (or unknown), NCSC-NL's likelihood and damage ratings as published, its release date and a link. A failed or partial read MUST be reported as such and MUST NOT clear advisories read before.

#### Scenario: An NCSC-NL advisory about the server version in use

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** NCSC-NL checks are on and the NCSC-NL directory holds an advisory for "Nextcloud Server" whose version range covers the installed server version
- **WHEN** the advisory sweep runs and the admin opens the Advisories tab
- **THEN** the Nextcloud server block MUST list the advisory with its NCSC tracking id, title, CVE ids and NCSC-NL's likelihood and damage ratings
- **AND** the block MUST say the installed server version is affected

#### Scenario: No version stated is not an alarm

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** NCSC-NL checks are on and the only NCSC-NL advisory about "Nextcloud Server" gives the version range `vers:unknown/*`
- **WHEN** the advisory sweep runs and the admin opens the Advisories tab
- **THEN** the Nextcloud server block MUST list the advisory with the line that NCSC-NL does not state which versions are affected
- **AND** the block MUST NOT say the installed server version is affected because of it

#### Scenario: The same CVE is counted once

@e2e exclude covered by AdvisoryServiceTest.

- **GIVEN** a Nextcloud advisory and an NCSC-NL advisory for the server that both name the same CVE id
- **WHEN** the advisory sweep runs
- **THEN** the server block MUST show one advisory carrying both ids

#### Scenario: Off by default

@e2e exclude covered by AdvisoryServiceTest.

- **GIVEN** an admin never switched on NCSC-NL checks
- **WHEN** the advisory sweep runs
- **THEN** no request MUST be sent to the NCSC-NL directory
