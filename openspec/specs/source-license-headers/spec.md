# source-license-headers Specification

## Purpose
Every PHP source file under `lib/` states its licence and copyright, so a reader and a REUSE check see the same EUPL-1.2 licence as `LICENSE` and `composer.json`.
## Requirements
### Requirement: Every lib PHP file carries an EUPL-1.2 licence and copyright header

> Superseded 2026-07-07: this app was relicensed from AGPL-3.0 to EUPL-1.2 to match the
> Conduction fleet standard. See the archived change `add-spdx-license-headers` for the
> original AGPL rationale, now reversed.

Every PHP file under `lib/` MUST carry a licence/copyright header in its top docblock
declaring the repository licence (EUPL-1.2): a `@copyright` tag (Conduction B.V.),
a `@license EUPL-1.2` tag, and the REUSE `SPDX-License-Identifier:
EUPL-1.2` and `SPDX-FileCopyrightText` tags. The declared per-file licence MUST
match the `LICENSE` file and `composer.json` (both EUPL-1.2). No `lib/` PHP file may ship
with an absent or contradictory licence header.

#### Scenario: A lib source file declares its EUPL-1.2 licence

- **WHEN** any `lib/**/*.php` file is inspected
- **THEN** its top docblock MUST contain `@license EUPL-1.2`, `@copyright`, and `SPDX-License-Identifier: EUPL-1.2`
- **AND** the value MUST match the repository `LICENSE` (EUPL-1.2) and `composer.json`

@e2e exclude source-header presence is a static REUSE/gate check, not a runtime UI flow.

#### Scenario: The spdx-headers gate passes

- **WHEN** the `spdx-headers` quality gate scans `lib/`
- **THEN** it MUST report zero files missing `@license`/`@copyright`
- **AND** the count of `lib/**/*.php` files with `SPDX-License-Identifier` MUST equal the total count of such files

@e2e exclude source-header presence is a static REUSE/gate check, not a runtime UI flow.

