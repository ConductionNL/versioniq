# external-sources Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-pins-checks-and-pending](../../)

## ADDED Requirements

### Requirement: The card names where the installed version came from

Every app card MUST name the source of its installed version, apart from the bound source. When the last install Versioniq finalized put the installed version in place, the card MUST name that install's source. When the installed version differs from that record, or there is no record, the card MUST say the version was changed outside Versioniq and name the version. `GET /api/apps` MUST carry this as `provenance` with `kind` (`versioniq` or `outside`) and `sourceId`.

#### Scenario: A version put in place outside Versioniq

- **GIVEN** Versioniq last installed Deck 1.14.1 from the App Store and the instance now runs Deck 1.15.0
- **WHEN** an admin opens the Apps tab
- **THEN** the Deck card MUST say Deck was set to 1.15.0 outside Versioniq

#### Scenario: A version installed from GitHub on an unbound app

- **GIVEN** an admin installed Pipelinq 1.12.0 once from GitHub with a one-off source, and the app stays bound to the App Store
- **WHEN** the admin opens the Apps tab
- **THEN** the card MUST name GitHub as the source of 1.12.0 and the App Store as the binding
