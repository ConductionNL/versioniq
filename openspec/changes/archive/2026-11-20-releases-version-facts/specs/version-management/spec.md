# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [releases-version-facts](../../)

## ADDED Requirements

### Requirement: Every version carries its release date

Every entry `GET /api/app/{appId}/versions` returns MUST carry `releasedAt`, in ISO 8601 UTC: the App Store release's `created` time, or the forge release's `published_at` time. It MUST be null when the source gives none or it cannot be read, and a missing date MUST NOT fail the listing. The version picker MUST show the date on every version that has one, and `occ versioniq:versions` MUST print it in a "Released" column and in its JSON.

#### Scenario: An admin rolls back to the version from before the summer

- **GIVEN** `openregister` is bound to the App Store, which lists 2.3.0 created 2026-05-12 and 2.4.0 created 2026-07-20
- **WHEN** an admin opens its version list
- **THEN** 2.3.0 MUST read "Released" with 12 May 2026 and 2.4.0 with 20 July 2026, in the admin's locale

#### Scenario: A forge release without a date

- **GIVEN** a forge release that carries no `published_at`
- **WHEN** the version list loads
- **THEN** that entry MUST carry `releasedAt: null`, show no date, and every other entry MUST keep its date

### Requirement: Every version shows how widely it runs and how installs of it went

Every forge version entry MUST carry `downloads`: the download count of the release asset that matches the binding's asset pattern, or null when no single asset matches. App Store entries MUST carry `downloads: null`, and the picker MUST show nothing for null rather than a zero. Every entry MUST carry `outcomes` with the number of successful and failed installs of that version recorded in this instance's audit trail. `GET /api/app/{appId}/outcomes` MUST be admin-only and MUST return those counts per version. When connections to other instances exist, the picker MUST add the counts each connected instance returns, and MUST name any instance it could not read.

#### Scenario: A forge release shows its downloads

- **GIVEN** `hermiq` is bound to `github:ConductionNL/hermiq` and the matching asset of release 1.4.0 was downloaded 312 times
- **WHEN** an admin opens its version list
- **THEN** 1.4.0 MUST show 312 downloads

#### Scenario: Installs on other instances are counted

- **GIVEN** production is connected to test and acceptance, 2.4.1 installed once on each of them and failed once on test, and never on production
- **WHEN** an admin on production opens the version list of `openregister`
- **THEN** 2.4.1 MUST read "Installed 2 times on 2 instances, 1 failure"

#### Scenario: A non-admin cannot read outcomes

- **GIVEN** a user who is not an admin
- **WHEN** they call `GET /ocs/v2.php/apps/versioniq/api/app/openregister/outcomes`
- **THEN** the response MUST be 403 and carry no counts
