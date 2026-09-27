# security-advisory-correlation Specification

## Purpose
TBD - created by archiving change security-advisory-correlation. Update Purpose after archive.
## Requirements
### Requirement: The installed/pinned version is correlated against known security advisories

For each installed app, the system MUST resolve whether the currently-installed or
admin-pinned version is affected by a published security advisory, using the app's bound
source: the Nextcloud App Store security information for store-sourced apps, and the source
adapter's advisory endpoint (e.g. GitHub/Codeberg security advisories) for external-sourced
apps. External calls MUST reuse the existing source-adapter and PAT/credential path (no new
bespoke HTTP client, no secret held outside the existing management). The correlation MUST be
read-only and MUST NOT change any installed or pinned version.

#### Scenario: A pinned older version with an open advisory is flagged

@e2e exclude the /api/advisories endpoint correlates every installed app (too slow for e2e); the pinned-to-vulnerable correlation is unit-tested in AdvisoryService and was confirmed live against the fixture (state=pinned-to-vulnerable, recommended 1.1.0).

- **GIVEN** an app the admin has pinned to an older version that has a published security advisory
- **WHEN** the version list is shown
- **THEN** that app MUST be marked `pinned-to-vulnerable`, with the advisory id, severity, and summary
- **AND** the system MUST recommend the nearest version that resolves the advisory
- **AND** the system MUST NOT change the pin automatically

#### Scenario: An app with no advisory shows a clean state

- **GIVEN** an installed app whose current version has no known advisory from its bound source
- **THEN** its advisory state MUST be `none`

@e2e exclude advisory resolution is unit-tested against a stubbed source-adapter advisory feed (store + external); a Playwright badge smoke follows once a live fixture exists.

### Requirement: The admin is notified and stays in control

The system MUST be able to notify an administrator (via the Nextcloud notification API) when
a newly-published advisory affects an installed or pinned version. The system MUST NOT
auto-update or auto-unpin — it surfaces the advisory and the recommended safe version, and
the administrator decides.

#### Scenario: A new advisory affecting a pinned version notifies the admin

- **GIVEN** an app pinned to a version, and a newly-published advisory affecting that version
- **WHEN** the scheduled advisory refresh runs
- **THEN** an admin notification MUST be raised naming the app, version, and advisory
- **AND** no version change MUST occur automatically

@e2e exclude notify-on-new-advisory covered by the refresh-job unit test; no auto-change asserted (job performs no install/pin mutation).


### Requirement: Every stored advisory is reachable from a page, with its severity

The admin page MUST show every advisory the last sweep stored, including the rows about the
Nextcloud server itself (the `:server` row, which no app card can carry), and MUST render the
severity of each advisory (`low`, `medium`, `high`, `critical` or `unknown`). The advisory
badge on an app card MUST carry the highest severity among that app's advisories, so a
critical and a low advisory do not look the same.

#### Scenario: Server advisories on the Advisories tab

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** the advisory sweep stored a `:server` row
- **WHEN** the admin opens the Advisories tab
- **THEN** a "Nextcloud server" section MUST show the installed server version and each server advisory with its id, summary and severity
- **AND** when that version is affected, the section MUST say so and name the recommended version
- **AND** when no `:server` row exists, the section MUST say that no published advisory is about this server version

#### Scenario: Severity on the app card badge

@e2e exclude a badge only renders once a sweep has found an advisory for an installed app, which needs a live feed fixture; covered by src/utils/advisories.spec.ts and src/components/AdvisoriesPanel.spec.ts.

- **GIVEN** an app with a low and a critical advisory
- **WHEN** the Apps tab renders its card
- **THEN** the advisory badge MUST read the state followed by "Critical"
