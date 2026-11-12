# platform-properties Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [platform-properties](../../)

## ADDED Requirements

### Requirement: Versioniq runs inside the Nextcloud instance with no SaaS dependency

Versioniq MUST keep all of its state in the Nextcloud instance it runs on: its database tables and its app config. It MUST NOT need an account, licence server or hosted service run by Conduction or anyone else. The only outbound calls it makes MUST go to the release sources and the advisory feed an admin uses, and each of those addresses MUST be overridable on the instance: `appstore.api_base` for the App Store, `forge.github.api_base` for GitHub, `forge.forgejo.api_base` for a Forgejo or Gitea host, and `advisory.feed_base` for the advisory feed.

#### Scenario: An instance with no internet access points Versioniq at internal mirrors

- **GIVEN** an admin sets `appstore.api_base`, `forge.github.api_base` and `advisory.feed_base` to internal mirror addresses
- **WHEN** Versioniq lists versions, installs a version and refreshes advisories
- **THEN** every outbound request MUST go to the mirror addresses
- **AND** no request MUST go to apps.nextcloud.com, api.github.com or a Conduction host

#### Scenario: Versioniq keeps working when Conduction's services are unreachable

- **GIVEN** no Conduction host can be reached from the instance
- **WHEN** an admin uses any feature of Versioniq
- **THEN** the feature MUST work exactly as when those hosts can be reached

### Requirement: Every feature is available without a paid plan

Versioniq MUST be published under the EUPL-1.2 licence, declared in `appinfo/info.xml`. No feature MUST sit behind a paid tier, a subscription check or a licence key. A feature MUST NOT check who the instance pays before it runs.

#### Scenario: An admin on a free instance uses every tab

- **GIVEN** an instance with no subscription of any kind
- **WHEN** an admin opens every tab of Versioniq and uses its actions
- **THEN** every action MUST run
- **AND** no screen MUST ask for a licence key or an upgrade

#### Scenario: The licence is declared

- **WHEN** a reader opens `appinfo/info.xml`
- **THEN** the `licence` element MUST read `EUPL-1.2`
