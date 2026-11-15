# security-advisory-correlation Specification

## Purpose
An administrator learns which installed or pinned app versions are affected by a published security advisory, without leaving Nextcloud. The system reads Nextcloud's advisory feed and the forge advisories of bound sources on a schedule the admin sets, matches each advisory to the installed version per release branch, notifies admins of new advisories that affect them and sends a weekly digest of the rest. It never changes a version on its own.
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


### Requirement: Advisories are matched per release branch

When an advisory lists patched versions, the system MUST decide whether the installed version is
affected from that list, per `major.minor` branch, and not from the advisory's range text: when
the installed version's own branch has a patch listed, that patch alone decides (at or above it
is fixed, below it is affected); a branch with no patch listed falls through to the nearest
higher patch in the same major; a version newer than every patch for its major is not affected.
An advisory without a patched-version list MUST keep the range evaluation. Code:
`lib/Service/Advisory/BranchAwareRange.php` (`resolvePatch`), called from
`lib/Service/Advisory/AdvisoryService.php`.

#### Scenario: A backported fix on a maintained branch reads fixed

@e2e exclude needs a live feed fixture; covered by tests/unit/Service/Advisory/BranchAwareRangeTest.php, which sweeps the committed feed corpus (0 false positives in 458 probes, 0 misses in 412).

- **GIVEN** an advisory with patched versions 21.1.10, 22.0.11 and 23.0.3
- **WHEN** the installed version is 22.0.11
- **THEN** the app MUST NOT be reported as affected
- **AND** an installed 22.0.10 MUST be reported as affected, with 22.0.11 as the fix

#### Scenario: Several lower bounds do not hide an affected version

@e2e exclude covered by tests/unit/Service/Advisory/BranchAwareRangeTest.php.

- **GIVEN** an advisory whose range reads ">= 3.5.0, >= 3.7.0, >= 4.1.0, >= 4.3.0" and whose patched versions are 3.7.25, 5.5.16, 5.6.20 and 5.7.13
- **WHEN** the installed version is 3.6.0
- **THEN** the app MUST be reported as affected

### Requirement: The admin sets how often advisories are checked

The advisory refresh job MUST run every N hours, where N is the interval the admin saved, 6 by
default, between 1 and 24. The Apps tab MUST offer the interval in its security advisory
checks settings, reading the bounds from `GET /api/advisory/settings`. `PUT
/api/advisory/settings` MUST be admin-only and password-confirmed, and MUST refuse a value that
is not a number or lies outside 1 to 24 with HTTP 400. The job reads the interval when it is
constructed, so a change applies from the next run. Before the first check, the freshness line
MUST name the saved interval. Code: `lib/Service/Advisory/AdvisorySettingsStore.php`,
`lib/BackgroundJob/AdvisoryRefreshJob.php`, `lib/Controller/ApiController.php`
(`advisorySettings`, `updateAdvisorySettings`), `src/App.vue` (`advisory-interval`),
`src/utils/advisoryFreshness.ts`.

#### Scenario: The admin changes the interval

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** the admin opens the security advisory checks settings
- **WHEN** they set the interval to 12 hours and save
- **THEN** `GET /api/advisory/settings` MUST return `intervalHours` 12
- **AND** a request for 30 hours MUST be refused with HTTP 400 and leave 12 stored

### Requirement: Non-urgent advisories arrive in a weekly digest

Once a week the system MUST send every admin one `advisory_digest` notification that counts the
apps with published advisories that do not affect the installed version, and the advisories in
total. The digest MUST NOT be sent when it is switched off, when one went out in the last seven
days, or when there is nothing to report; a week with nothing to report MUST NOT start the
seven-day wait. A failed dispatch MUST NOT count as sent. The digest is on by default, and the
admin switches it in the security advisory checks settings (`digestEnabled`). The notifier MUST
render the subject as "Weekly security advisory digest". Code:
`lib/Service/Advisory/AdvisoryDigestNotifier.php` (`sendIfDue`), called from
`lib/BackgroundJob/AdvisoryRefreshJob.php`, rendered by `lib/Notification/Notifier.php`.

#### Scenario: The digest goes out once a week

@e2e exclude the digest fires from a background job on a seven-day clock; covered by tests/unit/Service/Advisory/AdvisoryDigestNotifierTest.php.

- **GIVEN** the digest is on, none was sent in the last seven days, and two apps have advisories that do not affect their installed version
- **WHEN** the advisory refresh job runs
- **THEN** every admin MUST receive one "Weekly security advisory digest" notification naming 2 apps
- **AND** the next run within seven days MUST NOT send another

#### Scenario: A switched-off digest stays silent

@e2e exclude covered by tests/unit/Service/Advisory/AdvisoryDigestNotifierTest.php.

- **GIVEN** the admin switched the digest off
- **WHEN** the advisory refresh job runs
- **THEN** no `advisory_digest` notification MUST be sent
