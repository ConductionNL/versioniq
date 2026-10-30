# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-pins-checks-and-pending](../../)

## ADDED Requirements

### Requirement: The check line says whether the last sweep succeeded

The advisory sweep and the update availability sweep MUST each store their outcome with their time: `ok` when every source was read, `partial` when some apps could not be checked, `failed` when the feed or every source failed. `GET /api/advisories` and `GET /api/updates` MUST return `status` and the app ids that could not be checked. The Apps tab check line MUST say when each sweep ran and, when it was not `ok`, that it failed or was partial and which apps were not checked.

#### Scenario: A failed advisory feed

- **GIVEN** the advisory feed answered with an error at 06:00
- **WHEN** an admin opens the Apps tab
- **THEN** the check line MUST say the advisories could not be checked at 06:00
- **AND** it MUST NOT read as checked

#### Scenario: Some apps could not be checked for updates

- **GIVEN** the 06:02 availability sweep could not reach the source of Pipelinq
- **WHEN** an admin opens the Apps tab
- **THEN** the check line MUST say updates were checked at 06:02 except for Pipelinq
