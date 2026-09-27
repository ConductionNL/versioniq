# alert-webhooks Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [releases-new-release-alerts](../../)

## Purpose

An admin sends Versioniq's alerts to a chat channel or a monitoring system, next to the Nextcloud bell.

## ADDED Requirements

### Requirement: An admin manages webhook targets

An admin MUST be able to add, edit, remove and test webhook targets, each with a name, an https address, a format (`json` or `chat`), the alert kinds it receives (`new_release`, `security`, `auto_update`, `pin_drift`, `token`), an optional signing secret and an on or off switch. Writes MUST require password confirmation and MUST be recorded in the audit trail. The secret MUST be stored encrypted and MUST NOT be returned by any endpoint. The page MUST show each target's last delivery time, status and error.

#### Scenario: An admin connects a Mattermost channel

- **GIVEN** a Mattermost incoming webhook address
- **WHEN** admin `alice` adds it as a `chat` target for `security` and `auto_update`, confirms her password, and clicks Send test
- **THEN** the channel MUST receive a test message
- **AND** the Alerts tab MUST show the target's last delivery as succeeded

#### Scenario: An http address is refused

- **WHEN** alice adds a target with an `http://` address
- **THEN** the response MUST be 400 and no target MUST be stored

### Requirement: Every Versioniq alert is delivered to matching targets in the background

Every alert Versioniq raises as a notification, for security advisories, automatic updates, pin drift, access tokens and new releases, MUST also be queued for delivery to every enabled target whose kinds include it. Delivery MUST run in a background job, never inside the job or request that raised the alert. A `json` target MUST receive the kind, the subject, its parameters, the English text, the instance address and the time. A `chat` target MUST receive a body with a `text` field. With a secret, each request MUST carry a timestamp header and an HMAC-SHA256 signature of the timestamp and the body. A delivery that does not get a 2xx answer MUST be retried, at most three attempts in all, and then recorded as failed on the target.

#### Scenario: A failed automatic update reaches the monitoring system

- **GIVEN** a signed `json` target for `auto_update`
- **WHEN** the nightly job fails to update `openregister` to 2.3.4
- **THEN** a delivery job MUST be queued, and when it runs the target MUST receive the `auto_update_failure` subject with app, version and hint
- **AND** the request MUST carry a signature the target can check with the shared secret

#### Scenario: A dead endpoint does not slow the nightly job

- **GIVEN** a target whose address does not answer
- **WHEN** the nightly job raises three alerts
- **THEN** the job MUST finish without waiting for the target
- **AND** after three failed attempts per alert the target MUST show the last error
