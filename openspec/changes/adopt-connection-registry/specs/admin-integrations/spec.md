# admin-integrations Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**:
- [adopt-connection-registry](../../)

## Purpose

Admins see Versioniq's outside connections on one page, with a status Versioniq can back.

## ADDED Requirements

### Requirement: REQ-VERSIONIQ-CONN-001 Versioniq declares its outside connections in one static file

Versioniq SHALL declare `appstore`, `github` and `advisories` in `lib/Settings/connections.json` in the shape of hydra connection-registry design D2 (hydra REQ-CONN-001). Every entry SHALL be `reportedOnly`, because none of the three has a setting that must be filled: an empty override means the public default answers. The file SHALL NOT declare a `codeberg` connection. Every `settingsUrl` SHALL point at an anchor the admin settings page renders.

#### Scenario: The declaration names this app and passes integriq's schema
@e2e exclude A static file with no browser surface; tests/unit/Settings/ConnectionsDeclarationTest.php checks the shape, the app id, unique keys and the anchors.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL validate
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique
- **AND** every `#section-…` anchor SHALL be an id in the admin settings page

#### Scenario: No row for a retired forge
@e2e exclude A static file with no browser surface; tests/unit/Settings/ConnectionsDeclarationTest.php asserts the key list.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** its keys are read
- **THEN** they SHALL be `appstore`, `github` and `advisories`
- **AND** no key SHALL name Codeberg

### Requirement: REQ-VERSIONIQ-CONN-002 Versioniq reports what a real request met

After an App Store catalogue fetch, a GitHub release or advisory request, and an advisory feed check, Versioniq SHALL report the outcome with `ConnectionStatusReportedEvent` (hydra REQ-CONN-004). A report SHALL go out when the status differs from the last one sent for that key, or when the last one is an hour old. A request to the Codeberg forge SHALL NOT be reported. After a GitHub token is saved, Versioniq SHALL send `ConnectionRefreshRequestedEvent` for `github` first and the report second (hydra#674). After a GitHub token is removed, it SHALL send the refresh alone. Both events SHALL be named by string and sent only when the class exists. Neither SHALL change the response of the request or job that sent it. A message SHALL name a host, never a repository path or a token.

#### Scenario: Saving a GitHub token refreshes, then reports
@e2e exclude The event is not observable from a browser; tests/unit/Service/Connection/ConnectionReportServiceTest.php and tests/unit/Controller/ApiConnectionReportTest.php assert the order and the unchanged response.

- **GIVEN** integriq is installed
- **WHEN** an admin saves a GitHub token that GitHub accepts
- **THEN** Versioniq SHALL send a refresh for `github`
- **AND** then a report `configured`

#### Scenario: A rate-limited GitHub request reads limited
@e2e exclude A rate limit cannot be produced on demand against a CI instance; tests/unit/Service/Connection/ConnectionReportServiceTest.php maps every answer, and tests/unit/Service/Source/ForgeReleaseSourceConnectionReportTest.php proves the release request hands it over.

- **GIVEN** a GitHub release request
- **WHEN** GitHub answers HTTP 403
- **THEN** Versioniq SHALL report `github` as `limited`
- **AND** the message SHALL name the API host and not the repository

#### Scenario: The same outcome is reported once an hour
@e2e exclude The throttle is time based; tests/unit/Service/Connection/ConnectionReportServiceTest.php drives the clock.

- **GIVEN** Versioniq reported `github` as `configured` ten minutes ago
- **WHEN** another GitHub request succeeds
- **THEN** no event SHALL be sent
- **AND** when a GitHub request then fails, a report `error` SHALL be sent at once

#### Scenario: A partly read advisory feed reads limited
@e2e exclude The feed is a remote GitHub endpoint; tests/unit/Service/Advisory/NextcloudAdvisoryFeedConnectionReportTest.php drives the outcomes.

- **GIVEN** the advisory check reads one page of the feed
- **WHEN** the second page answers HTTP 502
- **THEN** Versioniq SHALL report `advisories` as `limited` with the number of advisories read

#### Scenario: Without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/unit/Service/Connection/ConnectionReportServiceTest.php asserts nothing is sent, stored or logged when the class is absent.

- **GIVEN** integriq is not installed
- **WHEN** a fetch completes or a token is saved
- **THEN** no event SHALL be sent and nothing SHALL be logged
- **AND** the fetch or save SHALL answer as it did before this change

### Requirement: REQ-VERSIONIQ-CONN-003 An admin reads the connections on an Integrations tab

The Versioniq admin settings page SHALL render an Integrations tab over integriq's `app_connection` rows, filtered to `app` equal to `versioniq` (hydra REQ-CONN-006). The page is admin only through its settings section. The tab SHALL render only when integriq is installed, and SHALL send no request to the `integriq` register otherwise. The status column SHALL name all six statuses, `limited` included. The tab SHALL offer no form to add a row. Its Add integration action SHALL open `/apps/integriq/connections?app=versioniq&link=1`. A link to `#section-sources`, `#section-advisories` or `#section-integrations` SHALL open the tab that holds the anchor.

#### Scenario: The tab lists only the rows of versioniq
@e2e tests/e2e/integrations.spec.ts

- **GIVEN** versioniq and integriq are installed and integriq has synced the declaration
- **WHEN** an admin opens the Integrations tab
- **THEN** the tab SHALL list the three declared connections
- **AND** every listed row SHALL have `app` equal to `versioniq`

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/integrations.spec.ts

- **GIVEN** the Integrations tab
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=versioniq` and `link=1`

#### Scenario: A settings link opens the tab that holds its anchor
@e2e tests/e2e/integrations.spec.ts

- **GIVEN** the GitHub releases row links to `#section-sources`
- **WHEN** an admin opens `/settings/admin/versioniq#section-sources`
- **THEN** the Sources tab SHALL be selected

#### Scenario: Without integriq the tab is hidden
@e2e exclude The CI instance installs integriq; tests/unit/Settings/AdminTest.php asserts the initial state, and src/components/IntegrationsPanel.spec.ts that no request goes out.

- **GIVEN** integriq is not installed
- **WHEN** an admin opens the Versioniq admin settings page
- **THEN** no Integrations tab SHALL render
- **AND** no request SHALL go to the `integriq` register

#### Scenario: A connection that works in part reads Limited
@e2e exclude Only a rate-limited or partly read source produces limited; src/utils/connectionRegistry.spec.ts asserts the label and the Dutch catalogue.

- **GIVEN** a row whose status is `limited`
- **WHEN** the tab renders it
- **THEN** the cell SHALL read Limited, or Beperkt on a Dutch instance
