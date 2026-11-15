# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [sources-trust-audit-and-offline-feed](../../)

## ADDED Requirements

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
