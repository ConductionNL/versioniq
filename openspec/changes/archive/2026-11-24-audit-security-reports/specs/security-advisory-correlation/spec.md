# security-advisory-correlation Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [audit-security-reports](../../)

## ADDED Requirements

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
