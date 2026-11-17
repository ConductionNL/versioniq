# app-discovery Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-verified-publisher](../../)

## ADDED Requirements

### Requirement: Hits and cards name the publisher and the trust marks the data backs

Every Discover hit and every app card MUST name the app's publisher: the App Store authors, or the forge owner. They MUST show a trust mark only when a named source backs it: `Ships with Nextcloud` (the server ships the app), `Featured in the App Store` (the store's featured flag), `Verified organisation` (the GitHub organisation has a verified domain), and `On your trusted list` (the admin's allowlist). Each mark MUST say in its tooltip who made the claim. Hits whose names differ only in case, spaces, dashes, underscores or a trailing "app" MUST each show a similar-name hint that names the other publisher.

#### Scenario: An admin tells two whiteboard apps apart

- **GIVEN** the App Store lists `whiteboard` by Nextcloud GmbH, marked featured, and `white-board` by another author
- **WHEN** an admin searches Discover for "whiteboard"
- **THEN** the `whiteboard` hit MUST show "Nextcloud GmbH" and "Featured in the App Store"
- **AND** both hits MUST show a similar-name hint naming the other publisher

#### Scenario: A GitHub hit from a verified organisation

- **GIVEN** public GitHub search is on and a hit comes from an organisation whose domain GitHub verified
- **WHEN** an admin searches Discover
- **THEN** that hit MUST show the organisation as publisher and "Verified organisation"
- **AND** a hit from a personal account MUST show the account name and no verified mark
