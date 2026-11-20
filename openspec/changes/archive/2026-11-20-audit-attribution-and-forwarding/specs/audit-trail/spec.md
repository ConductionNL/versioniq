# audit-trail Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [audit-attribution-and-forwarding](../../)

## ADDED Requirements

### Requirement: Every audit row says where it came from

Every audit row MUST record its origin: `web` for the admin page, `cli` for `occ`, `job` for the nightly automatic update, `listener` for drift detection. The History tab MUST show it next to the actor and MUST let the admin filter on it. Rows written before this change MUST show the origin as unknown.

#### Scenario: An admin tells an automatic update from a command-line one

- **GIVEN** the nightly job updated `openregister` and an admin ran `occ versioniq:install calendar 5.1.0`
- **WHEN** the admin opens the History tab
- **THEN** the `openregister` row MUST read "Automatic" and the `calendar` row "Command line"
- **AND** filtering on "Automatic" MUST list only the `openregister` row

### Requirement: Changes to the update rules are audited

Setting or clearing an app's policy, changing the automatic update switch or window, and changing the advisory settings MUST each write an audit row with the actor, the origin and the value before and after. The policy selector MUST show who set the app's policy and when.

#### Scenario: Turning automatic updates on leaves a trace

- **GIVEN** automatic updates are off
- **WHEN** admin `alice` turns them on and sets the window to 02:00-04:00
- **THEN** the History tab MUST show an `auto_update_settings` row by `alice` reading `enabled: false -> true` and the old and new window

### Requirement: Audit rows reach the platform's audit log and activity stream

After recording an audit row, the system MUST dispatch the platform's critical action event with a sanitised message, so the `admin_audit` app writes it to the audit log target the admin configured. The system MUST publish installs, rollbacks, pins, unpins and policy changes to the activity stream of every admin, under a Versioniq activity setting. Neither MAY fail or change the operation it records.

#### Scenario: The security team's log collector sees an update

- **GIVEN** `admin_audit` is enabled with `log_type_audit` set to syslog
- **WHEN** an admin updates `openregister` through Versioniq
- **THEN** a syslog line MUST name Versioniq, the app, both versions, the actor and the origin

#### Scenario: An admin reads the update in their activity stream

- **GIVEN** the activity app is enabled and admin `bob` keeps the Versioniq activity setting on
- **WHEN** admin `alice` pins `openregister` to 2.3.0
- **THEN** `bob`'s activity stream MUST show that `alice` pinned `openregister` to 2.3.0
