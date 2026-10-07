---
status: implemented
---

# Admin API Specification

**Status**: implemented
**Scope**: versioniq
**Standards**: Nextcloud OCS API, OpenAPI 3.0.3, nextcloud/openapi-extractor

## Purpose

Everything an administrator does on the Versioniq page can also be done by a script or another system. The page itself is a client of the same OCS API, so there is no action the API lacks. The API is described in a generated `openapi.json` that ships with the app.

## Requirements

### Requirement: Every page action is an OCS API route

Every Versioniq action the admin page performs MUST go through an OCS route under
`/ocs/v2.php/apps/versioniq/api/`, declared with `#[ApiRoute]` on an `OCSController`
(`lib/Controller/ApiController.php`, `lib/Controller/SettingsController.php`,
`lib/Controller/ForgeController.php`). This covers listing apps and versions, installing a
version, source binding, pins, update policies, auto-update settings, advisories and their
settings, pending updates, the audit log, the artifact cache, discovery, tokens, trusted
sources and the instance settings. Each route MUST refuse a caller who is not a Nextcloud
administrator with HTTP 403, and each route that changes state MUST require password
confirmation. Responses MUST use the OCS envelope with typed data. Listing and installing are
also available as `occ` commands (cli-commands). The Integrations tab is the one exception: it reads integriq's connection rows through integriq, not through this API (adopt-connection-registry).

#### Scenario: A script lists the installed apps

@e2e tests/e2e/pending-updates.spec.ts

- **GIVEN** an administrator's app password
- **WHEN** a script calls `GET /ocs/v2.php/apps/versioniq/api/apps` with `OCS-APIRequest: true`
- **THEN** the response MUST carry the installed apps in the OCS `data` field

#### Scenario: A non-admin is refused

@e2e tests/e2e/pending-updates.spec.ts

- **GIVEN** an authenticated user who is not an administrator
- **WHEN** they call any Versioniq API route
- **THEN** the system MUST respond with HTTP 403
- **AND** no data MUST be returned

### Requirement: The API is described in a generated OpenAPI document

The app MUST ship `openapi.json`, an OpenAPI 3.0.3 description of its OCS routes generated from
the controllers' attributes and docblocks by `composer run openapi`
(`nextcloud/openapi-extractor`). The document's `info.version` is owned by the release
workflow. The `openapi` workflow (`.github/workflows/openapi.yml`) MUST regenerate the document
on every pull request and fail when the committed file differs from the generated one, so a
route added without regenerating the file is caught.

#### Scenario: A route added without regenerating the document fails CI

@e2e exclude a CI property, not a user path; enforced by .github/workflows/openapi.yml.

- **GIVEN** a pull request adds an `#[ApiRoute]` method and leaves `openapi.json` unchanged
- **WHEN** the `openapi` workflow runs `composer run openapi`
- **THEN** the working tree MUST differ from the commit
- **AND** the workflow MUST fail and show the missing paths

#### Scenario: An integrator reads the routes from the package

@e2e exclude a static file in the app package; no browser path.

- **GIVEN** an installed Versioniq
- **WHEN** an integrator opens `openapi.json` in the app directory
- **THEN** it MUST list each OCS route under `/ocs/v2.php/apps/versioniq/api/` with its parameters and responses
