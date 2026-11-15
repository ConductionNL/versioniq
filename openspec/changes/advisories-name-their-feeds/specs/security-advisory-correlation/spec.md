# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [advisories-name-their-feeds](../../)

## MODIFIED Requirements

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
