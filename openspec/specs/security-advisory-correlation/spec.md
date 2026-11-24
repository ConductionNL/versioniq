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
a newly-published advisory affects an installed or pinned version. Finding an advisory MUST NOT
change any version by itself: the advisory refresh and the notification path MUST stay
read-only. A version MAY change because of an advisory only when an admin set, before the
advisory was found, a policy that allows it: the app's auto-update level other than `none`,
together with the patch policy's handling for the advisory's severity. Such a change MUST happen
only in the nightly automatic update job, inside the update window, through the standard
installer, and MUST be notified and audited like any automatic update. The system MUST NOT
auto-unpin, and a pinned app MUST NOT be changed because of an advisory. Without such a
policy, the system surfaces the advisory and the recommended safe version, and the
administrator decides.

#### Scenario: A new advisory affecting a pinned version notifies the admin

- **GIVEN** an app pinned to a version, and a newly-published advisory affecting that version
- **WHEN** the scheduled advisory refresh runs
- **THEN** an admin notification MUST be raised naming the app, version, and advisory
- **AND** no version change MUST occur automatically

@e2e exclude notify-on-new-advisory covered by the refresh-job unit test; no auto-change asserted (job performs no install/pin mutation).

#### Scenario: An app without a policy is never changed by an advisory

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `calendar` has no auto-update policy and the advisory refresh finds its installed version affected
- **WHEN** the refresh runs and the nightly window passes
- **THEN** `calendar` MUST keep its installed version
- **AND** admins MUST be notified with the recommended version

#### Scenario: A policy set in advance lets the fix install in the window

@e2e tests/e2e/jobs.spec.ts

- **GIVEN** `openregister` is on level `security` and the refresh finds 2.3.0 affected with recommended version 2.3.5
- **WHEN** the refresh runs at 14:00
- **THEN** no version MUST change at 14:00
- **AND** the next nightly run inside the window MUST install 2.3.5 through the standard installer and notify the admins

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

### Requirement: Libraries bundled inside installed apps are checked against OSV

When an admin switched on the library check (off by default, with the setting stating that library names and versions are sent to OSV), a daily background job MUST read `vendor/composer/installed.json` of every enabled app, collect the PHP libraries and versions it lists except development-only packages and branch versions, and ask the OSV API about each distinct library and version in the `Packagist` ecosystem. For every library with a known vulnerability the system MUST store the advisory id, CVE ids, severity (from the record, else `unknown`), a summary, the first fixed version and a link, with the time of the check. The Advisories tab MUST show per app how many libraries were checked and each finding with its fixed version and the next step for the admin, MUST say when an app has no library manifest, and MUST say that JavaScript built into apps is not checked. An app card with findings MUST carry a badge linking to them. Library findings MUST NOT change the app's advisory state, the compliance status or any automatic install. Only libraries bundled in installed apps MUST be checked; nothing outside the instance.

#### Scenario: A vulnerable library inside an app is shown

@e2e tests/e2e/bundled-libraries.spec.ts

- **GIVEN** the library check is on, the fixture app bundles `vendor/library` 2.4.3, and OSV reports an advisory for it fixed in 2.4.5
- **WHEN** the library job runs and the admin opens the Advisories tab
- **THEN** the fixture app's "Bundled libraries" section MUST list `vendor/library` 2.4.3 with the advisory id, its severity and "Fixed in 2.4.5"
- **AND** the fixture app card MUST show a library advisory badge
- **AND** the app's own advisory state MUST NOT change

#### Scenario: A library known to be malicious is flagged as malicious

@e2e exclude covered by OsvClientTest and BundledLibrariesSection.spec.ts.

- **GIVEN** the library check is on, and OSV returns a record with a `MAL-` id for `vendor/evil` 1.0.0 bundled in the fixture app
- **WHEN** the library job runs and the admin opens the Advisories tab
- **THEN** the finding MUST be labelled "Known malicious package" with its `MAL-` id, not as a vulnerability with a fixed version
- **AND** the next step MUST tell the admin to remove or replace the app

#### Scenario: An app without a manifest is named, not skipped silently

@e2e exclude covered by BundledLibraryScannerTest and BundledLibrariesSection.spec.ts.

- **GIVEN** the library check is on and `calendar` ships no `vendor/composer/installed.json`
- **WHEN** the admin opens the Advisories tab after the job ran
- **THEN** the `calendar` block MUST say that no library manifest was found, so its libraries were not checked

#### Scenario: Nothing is sent while the check is off

@e2e exclude covered by LibraryAdvisoryJobTest.

- **GIVEN** an admin never switched on the library check
- **WHEN** the daily library job runs
- **THEN** no request MUST be sent to OSV

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

### Requirement: Each product lists its recent advisories with CVE ids and dates

The advisory sweep MUST keep, for every stored advisory, its CVE ids, its publication date and a link when the source provides them, and MUST store per app and for the server a history of all advisories for that target, newest first, each marked as affecting or not affecting the installed version. The Advisories tab MUST list that history per target, showing for each advisory its CVE ids, its source id, its date, its severity and whether it affects the installed version, with the five most recent shown first and the rest behind a "Show all" control. The advisory badge on an app card MUST link to that app's list on the Advisories tab. Missing CVE ids or dates MUST be shown as missing, never left blank without a word.

#### Scenario: An admin reads the recent CVEs for one app

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** the last sweep stored two advisories for `fixtureapp`, one from 2026-08-01 with CVE-2026-1111 affecting the installed version and one from 2025-11-02 fixed in it
- **WHEN** the admin clicks the advisory badge on the `fixtureapp` card
- **THEN** the Advisories tab MUST open at `fixtureapp`
- **AND** the list MUST show CVE-2026-1111 first with its date and "Affects the installed version", and the 2025 advisory second with "Fixed in the installed version"

#### Scenario: An advisory without a CVE id says so

@e2e exclude covered by AdvisoriesPanel.spec.ts.

- **GIVEN** a stored advisory with a GHSA id and no CVE id
- **WHEN** the Advisories tab renders it
- **THEN** it MUST show the GHSA id and "No CVE id"

### Requirement: An affected version without an installable fix says why

For every target whose installed version is affected, the sweep MUST record a fix status: `installable` when the bound source lists the recommended version and does not mark it incompatible, `no_fix_published` when no fixed version is known, `not_in_source` when a fixed version is named but the bound source does not list it, or `needs_newer_server` when the source lists the fix but marks it and every later fixing version incompatible with this server. `GET /api/advisories` MUST also report when a pin holds an app below an installable fix. The Advisories tab MUST state the reason in one sentence next to the affected state. For the server row, the reason MUST be limited to `installable` or `no_fix_published`.

#### Scenario: No fix is published yet

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** `fixtureapp` 1.0.0 is affected by an advisory that names no patched version and covers every listed version
- **WHEN** the admin opens the Advisories tab after the next check
- **THEN** the `fixtureapp` block MUST say that no fixed version is published yet

#### Scenario: The fix needs a newer Nextcloud

@e2e exclude needs an App Store release that is incompatible with the CI server; covered by FixStatusTest.

- **GIVEN** `calendar` is affected, the fix is 4.8.0, and the App Store marks 4.8.0 and every later release incompatible with this server
- **WHEN** the admin opens the Advisories tab
- **THEN** the `calendar` block MUST say the fix needs a newer Nextcloud than this server runs

### Requirement: The instance has one patch compliance status

`GET /api/advisories` MUST return a compliance status for the server and all apps together: `compliant` when the last check is no older than twice the advisory check interval, no target's installed version is affected and every app was checked; `not_compliant` when any target's installed version is affected, with the number of affected targets per highest severity; `unknown` when no check has completed, when the last check is older than twice the interval, or when no target is affected but some apps could not be checked, with that number. The Apps tab and the Advisories tab MUST show the status in one line.

#### Scenario: One affected app makes the instance not compliant

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** a fresh check found `fixtureapp` 1.0.0 affected by a medium advisory and nothing else affected
- **WHEN** the admin opens the Apps tab
- **THEN** it MUST read "Not compliant: 1 app runs an affected version (1 medium)"
- **AND** after `fixtureapp` is updated to 1.0.1 and the check runs again, it MUST read "Compliant"

#### Scenario: An old check is not reported as compliant

@e2e exclude needs a check older than twice the interval, and the e2e run cannot age it; covered by ComplianceStatusTest.

- **GIVEN** the advisory interval is 6 hours and the last check completed 13 hours ago with nothing affected
- **WHEN** the admin opens the Apps tab
- **THEN** the status MUST be unknown and say the check is out of date

### Requirement: An admin dismisses an advisory with a recorded reason

An admin MUST be able to dismiss one stored advisory for one app, or for the server, through `POST /api/advisory-triage/dismiss` (admin-only, password-confirmed), with a reason from `not_used`, `inaccurate`, `tolerable_risk`, `mitigated` or `fix_planned`, an optional comment of at most 1000 characters, and an optional end date that `fix_planned` requires. While a dismissal is active, the advisory MUST NOT count toward the app's advisory state, its badge severity, notifications, the weekly digest or automatic security installs, and it MUST stay listed under Dismissed with the reason, comment, admin, time and end date. A dismissal MUST end on its end date and when the app's installed version changes. An admin MUST be able to reopen it through `POST /api/advisory-triage/reopen`. Every dismissal and reopen MUST write an audit entry. Dismissals MUST be applied when the snapshot is read, never written into it.

#### Scenario: A dismissed advisory stops counting but stays visible

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** the last sweep stored one server advisory that affects the installed version
- **WHEN** admin `alice` dismisses it on the Advisories tab as "not used" with the comment "Public shares are off" and confirms her password
- **THEN** the server block MUST no longer say the version is affected
- **AND** the advisory MUST be listed under Dismissed with the reason, the comment, alice and the date
- **AND** the History tab MUST list an `advisory_dismiss` entry by alice

#### Scenario: An update ends the dismissal

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** an advisory on `openregister` 2.3.0 was dismissed
- **WHEN** `openregister` is updated to 2.3.2, which the advisory still affects
- **THEN** the advisory MUST count again and the card MUST show it

#### Scenario: A planned fix needs an end date

@e2e exclude covered by AdvisoryTriageControllerTest.

- **GIVEN** an admin dismisses an advisory with reason `fix_planned` and no end date
- **WHEN** the request reaches `POST /api/advisory-triage/dismiss`
- **THEN** the response MUST be 400 and nothing MUST be stored

### Requirement: An admin assigns an advisory to an owner

An admin MUST be able to assign a stored advisory to a user in the admin group or to a Nextcloud group through `POST /api/advisory-triage/assign` (admin-only, password-confirmed), and to remove the assignment. For a group, the owners MUST be the group's members who are admins. Assigning MUST notify the owners with the app, the advisory and who assigned it, and MUST write an audit entry. The Advisories tab MUST show each advisory's owner and MUST offer a filter for advisories assigned to the current admin, directly or through a group.

#### Scenario: An admin hands an advisory to a colleague

@e2e tests/e2e/advisories.spec.ts

- **GIVEN** admins `alice` and `bob`, and an advisory affecting `openregister`
- **WHEN** alice assigns it to bob
- **THEN** bob MUST receive a notification naming `openregister`, the advisory and alice
- **AND** bob MUST see it when he filters the Advisories tab on "Assigned to me"

#### Scenario: A non-admin cannot be the owner

@e2e exclude covered by AdvisoryTriageControllerTest.

- **GIVEN** `carol` is not an admin
- **WHEN** an admin assigns an advisory to `user:carol`
- **THEN** the response MUST be 400 and the advisory MUST stay unassigned

### Requirement: Remediation is measured per severity against the patch policy deadlines

`GET /api/reports/security` (admin-only) MUST return for a period, per severity (`critical`, `high`, `medium`, `low`, `unknown`): the advisories opened, resolved by an update, resolved by a dismissal, resolved within the deadline, open past the deadline at the end of the period, the open exceptions, and the median days from open to resolved; the count of installs by outcome; the security updates applied; and the same shares per calendar month for the twelve months ending with the period, limited to the audit retention. "Within the deadline" MUST mean resolved no later than the moment the advisory was opened plus the patch policy's deadline for its severity. Advisories that were already open when tracking started MUST NOT count in the time figures. Without a deadline for a severity, the within-deadline share MUST be reported as unavailable, and the page MUST say to set a deadline in the patch policy. The History tab MUST show the report for a chosen period and MUST export it to CSV.

#### Scenario: Critical advisories fixed within their deadline

@e2e exclude needs advisories open for several days; the e2e run cannot move the clock; covered by SecurityReportServiceTest.

- **GIVEN** the patch policy sets 3 days for critical, and last month two critical advisories opened, one fixed by an update after 2 days and one after 5 days
- **WHEN** an admin opens the security report for last month
- **THEN** the critical row MUST show 2 opened, 2 resolved by update, 1 within the deadline (50%) and a median of 3.5 days

#### Scenario: No deadline set

@e2e tests/e2e/audit.spec.ts

- **GIVEN** no patch policy deadline is recorded
- **WHEN** an admin opens the security report for this month
- **THEN** the within-deadline share MUST read as unavailable
- **AND** the page MUST say to set deadlines in the patch policy
