# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-app-details-card](../../)

## ADDED Requirements

### Requirement: The version view shows an About card with publisher, licence and links

When an admin opens an app's versions, the page MUST show an "About <app>" card beside the version list, as drawn on the VqAppVersies board. The card MUST show the publisher (the author in the app's `appinfo/info.xml`), the licence, the installed version with its enabled or disabled state, and the app's description. It MUST link to the website, the source repository and the issue tracker the app declares, each opening in a new tab. A link the app does not declare MUST be left out, not shown empty. The list payload of `GET /api/apps` MUST carry `author`, `licence` and `links` (`website`, `repository`, `bugs`) for every installed app.

#### Scenario: An admin reads who publishes an app and under which licence

- **GIVEN** Dossiq 0.4.48 is installed and enabled, and its `info.xml` names author "Conduction", licence `EUPL-1.2`, a website, a repository and a bug tracker
- **WHEN** the admin opens the versions of Dossiq
- **THEN** the About card MUST show "Conduction", "EUPL-1.2" and "0.4.48, enabled"
- **AND** it MUST link to the website, the source and the issue tracker

#### Scenario: An app without a bug tracker

- **GIVEN** an installed app whose `info.xml` declares a website but no `bugs` element
- **WHEN** the admin opens its versions
- **THEN** the card MUST show the website link
- **AND** it MUST NOT show a "Report a problem" link

#### Scenario: The list payload carries the metadata

- **WHEN** an admin calls `GET /api/apps`
- **THEN** every installed app MUST carry `author`, `licence` and `links`
- **AND** a value the app does not declare MUST be `null`
