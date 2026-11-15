# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [write-guards-and-advisory-alerts](../../)

## MODIFIED Requirements

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
