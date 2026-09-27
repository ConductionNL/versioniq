# release-channels Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [releases-channel-and-prereleases](../../)

## Purpose

An admin decides per app whether it takes stable releases, pre-releases or long-term support releases only, and sees the other versions only when they ask for them.

## ADDED Requirements

### Requirement: Every listed version says whether it is a pre-release

Every entry `GET /api/app/{appId}/versions` returns MUST carry `preRelease`. It MUST be true when the version number carries a suffix after its numeric core, when the forge release is marked as a pre-release, or when the App Store release is marked as a nightly. It MUST be false otherwise.

#### Scenario: A forge release marked pre-release is recognised

- **GIVEN** `hermiq` is bound to `github:ConductionNL/hermiq`, and release `2.5.0` is marked pre-release on GitHub while `2.4.3` is not
- **WHEN** an admin opens the version list for `hermiq`
- **THEN** 2.5.0 MUST carry `preRelease: true` and 2.4.3 MUST carry `preRelease: false`

#### Scenario: A suffixed version is a pre-release

- **GIVEN** the App Store lists `openregister` 2.6.0-beta.1 and 2.5.2
- **WHEN** the version list loads
- **THEN** 2.6.0-beta.1 MUST carry `preRelease: true`

### Requirement: An admin sets a release channel for the instance and per app

An admin MUST be able to set an instance default channel and a channel per app, each one of `server`, `stable`, `beta` or `lts`, through password-confirmed, admin-only endpoints. An app without its own channel MUST use the default, and an absent default MUST mean `server`. `server` MUST admit what the server's update channel admits: no pre-releases on `stable`, `production` or `enterprise`, and every version on any other channel. `stable` MUST admit every version that is not a pre-release. `beta` MUST admit every version. Every entry in a version list MUST carry `onChannel`, and the envelope MUST name the effective channel. Versioniq MUST NOT change the server's own update channel. Every change MUST be recorded in the audit trail.

#### Scenario: One app follows its beta releases

- **GIVEN** the server runs the stable update channel and the instance default is `server`
- **WHEN** admin `alice` sets the channel of `hermiq` to `beta` and confirms her password
- **THEN** the version list of `hermiq` MUST mark 2.5.0-rc.1 `onChannel: true`
- **AND** the version list of `openregister` MUST still mark 2.6.0-beta.1 `onChannel: false`

#### Scenario: A non-admin cannot change a channel

- **GIVEN** a user who is not an admin
- **WHEN** they call `PUT /ocs/v2.php/apps/versioniq/api/app/hermiq/channel`
- **THEN** the response MUST be 403 and no channel MUST change

### Requirement: The version picker shows the app's channel and reveals the rest on request

The version picker MUST show only versions with `onChannel: true`, and MUST offer a "Show all versions" switch that reveals the others, each tagged "Pre-release" or "Not long-term support". Safe mode MUST block a version that is not on the app's channel, and MUST keep blocking downgrades. The picker MUST name the effective channel.

#### Scenario: An admin looks at a beta without changing the channel

- **GIVEN** `openregister` follows the stable channel and the App Store lists 2.6.0-beta.1
- **WHEN** alice opens its version list
- **THEN** 2.6.0-beta.1 MUST NOT be listed
- **AND** after she turns on "Show all versions" it MUST be listed and tagged "Pre-release"

### Requirement: The nightly job takes only versions on the app's channel

The automatic update job MUST pass only versions with `onChannel: true` to candidate selection. With an effective channel that excludes pre-releases, no policy level, `all` included, MUST install a pre-release.

#### Scenario: A policy of all skips a pre-release on a stable channel

- **GIVEN** `openregister` 2.5.2 is installed with policy `all`, its effective channel is `stable`, and the source lists 2.6.0-beta.1 and 2.5.3
- **WHEN** the job runs inside the window
- **THEN** it MUST install 2.5.3 and MUST NOT consider 2.6.0-beta.1

### Requirement: The long-term support channel offers only marked release lines

An admin MUST be able to mark release lines of an app, as `major.minor` or `major`, as long-term support. A forge release whose name or tag contains the whole word "LTS", in any case, MUST mark its own `major.minor` line. The `lts` channel MUST admit only versions that are not pre-releases and lie on a marked line. An app on the `lts` channel with no marked line MUST admit no version, and the picker MUST say that no long-term support lines are marked.

#### Scenario: Only the long-term support line is offered

- **GIVEN** `openregister` is on the `lts` channel with line 2.3 marked, and the source lists 2.3.9, 2.4.1 and 2.5.0
- **WHEN** alice opens its version list
- **THEN** only 2.3.9 MUST be listed
- **AND** the nightly job MUST NOT consider 2.4.1 or 2.5.0

#### Scenario: No lines are marked

- **GIVEN** `calendar` is on the `lts` channel and no line is marked, by an admin or a publisher
- **WHEN** alice opens its version list
- **THEN** the picker MUST say "No long-term support lines are marked for this app" and list no version until "Show all versions" is on

### Requirement: The channel is visible and settable from the CLI

`occ versioniq:versions <appId>` MUST print whether each version is on the app's channel, and `--json` MUST carry `preRelease`, `onChannel` and the envelope's `channel`. `occ versioniq:channel` MUST print the default and every per-app channel. `occ versioniq:channel <appId> <channel>` MUST set an app's channel, `--lts-lines=` MUST set its lines, and an unknown channel MUST exit non-zero without a change.

#### Scenario: A provisioning script puts one app on beta

- **WHEN** an admin runs `occ versioniq:channel hermiq beta`
- **THEN** the effective channel of `hermiq` MUST be `beta` and the exit code MUST be 0

#### Scenario: A typo is refused

- **WHEN** an admin runs `occ versioniq:channel hermiq betta`
- **THEN** the command MUST name the four valid channels, change nothing and exit non-zero
