# security-advisory-correlation Specification

## Purpose
An administrator learns which installed or pinned app versions are affected by a published security advisory, without leaving Nextcloud. The system reads Nextcloud's advisory feed and the forge advisories of bound sources on a schedule the admin sets, matches each advisory to the installed version per release branch, notifies admins of new advisories that affect them and sends a weekly digest of the rest. It never changes a version on its own.

## Requirements

### Requirement: The installed/pinned version is correlated against known security advisories

For each installed app, the system MUST resolve whether the currently-installed or
admin-pinned version is affected by a published security advisory. It MUST read two kinds of
source. Nextcloud's own advisory feed (the records published on `nextcloud/security-advisories`,
at the address in `advisory.feed_base` when an admin set one) MUST be read once per sweep and
applies to every installed app and to the server, whatever the app's source. For an app bound
to a GitHub repository or a self-hosted Forgejo repository, the system MUST also read that
repository's security advisories. External calls MUST reuse the existing source-adapter and
PAT/credential path (no new bespoke HTTP client, no secret held outside the existing
management). The correlation MUST be read-only and MUST NOT change any installed or pinned
version.

#### Scenario: A pinned older version with an open advisory is flagged

- **GIVEN** an app the admin has pinned to an older version that has a published security advisory
- **WHEN** the version list is shown
- **THEN** that app MUST be marked `pinned-to-vulnerable`, with the advisory id, severity, and summary
- **AND** the system MUST recommend the nearest version that resolves the advisory
- **AND** the system MUST NOT change the pin automatically

#### Scenario: An app with no advisory shows a clean state

- **GIVEN** an installed app whose current version has no known advisory from its bound source
- **THEN** its advisory state MUST be `none`

#### Scenario: An App Store app is checked against Nextcloud's advisory feed

@e2e exclude the feed is read by a background sweep; covered by tests/unit/Service/Advisory/AdvisoryServiceTest.php (testAnAppStoreAppIsCorrelatedFromTheCentralFeed) and tests/unit/Service/Advisory/NextcloudAdvisoryFeedTest.php.

- **GIVEN** `calendar` 5.1.2 is installed from the App Store, and Nextcloud's advisory feed holds an advisory for `calendar` fixed in 5.1.3
- **WHEN** the advisory sweep runs
- **THEN** `calendar` MUST be marked affected, with the advisory id and 5.1.3 as the recommended version
- **AND** the sweep MUST read the feed once, not once per app

#### Scenario: A GitHub-bound app is checked against its repository's advisories

@e2e exclude needs a live repository with a published advisory; covered by tests/unit/Service/Source/ForgeReleaseSourceTest.php and tests/unit/Service/Source/ForgeReleaseSourceBackgroundTokenTest.php (testAdvisoriesUseTheSharedTokenWithoutASessionUser).

- **GIVEN** `hermiq` 1.4.0 is bound to `github:ConductionNL/hermiq`, and that repository published an advisory affecting 1.4.0
- **WHEN** the advisory sweep runs
- **THEN** the sweep MUST read `/repos/ConductionNL/hermiq/security-advisories` on the GitHub API, with a matching shared token when the repository is private
- **AND** `hermiq` MUST be marked affected with that advisory's id

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

#### Scenario: A critical advisory reaches the admin at the next sweep

@e2e exclude the notification fires from a background job; covered by tests/unit/Service/Advisory/AdvisoryNotifierTest.php.

- **GIVEN** the sweep runs every hour, and a critical advisory is published for the installed version of `calendar`
- **WHEN** the next sweep runs
- **THEN** every admin MUST get a notification naming `calendar`, its version and the advisory
- **AND** the advisory MUST NOT wait for the weekly digest, which only counts advisories that do not affect the installed version

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

### Requirement: An instance without internet imports a local copy of the advisory feed

The advisory feed setting MUST offer to import a local copy: an admin uploads a feed file in the format the feed address serves. Versioniq MUST validate the file, keep it in its app data and, when `advisory.feed_source` is `local`, read advisories from that copy without any network request. The setting MUST show the date of the copy and warn when it is older than 30 days. A file that does not parse MUST be refused and the previous copy kept.

#### Scenario: An offline instance checks advisories from an imported copy

- **GIVEN** an instance with no internet access and a feed file downloaded elsewhere on 20 October
- **WHEN** an admin imports the file and sets the feed source to the local copy
- **THEN** the next advisory sweep MUST correlate installed versions against that copy
- **AND** no request MUST go to the feed address
- **AND** the setting MUST show "Local copy of 20 Oct"

#### Scenario: A broken file

- **WHEN** an admin imports a file that is not a valid feed
- **THEN** the import MUST be refused with a message saying why
- **AND** the previous copy MUST stay in use

### Requirement: An admin updates to the safe version straight from the advisory

When an app's advisory state names a recommended version, the card MUST offer "Update to safe version {version}". It MUST show which advisory that version resolves, and after one confirmation it MUST install that version through the normal install endpoint, with every guard of that endpoint in place. The system MUST still never install it without the admin's action.

#### Scenario: An admin fixes an advisory in one action

- **GIVEN** `openregister` 2.3.0 is affected by advisory GHSA-xxxx and the recommended version is 2.3.4
- **WHEN** an admin clicks "Update to safe version 2.3.4" on the card and confirms
- **THEN** `openregister` 2.3.4 MUST be installed through the install endpoint
- **AND** after the next advisory sweep the card MUST no longer show the advisory
