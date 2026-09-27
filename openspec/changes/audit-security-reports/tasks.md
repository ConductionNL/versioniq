# audit-security-reports tasks

## 1. Record exposure and server updates

- [ ] 1.1 Add the migration step with the nullable `advisories` column (guarded by `hasColumn`), the field on `AuditEntry`, and the optional parameter on `AuditLogger::record()`. Verify: `tests/unit/Service/Audit/AuditLoggerTest.php` writes and reads the JSON list; a unit test on the migration step with a schema double asserts it adds the column when missing and changes nothing when present.
- [ ] 1.2 Add `AdvisoryExposureTracker::track()` per design D1 and D3, and call it in `AdvisoryRefreshJob` after `save()`. Verify: `tests/unit/Service/Advisory/AdvisoryExposureTrackerTest.php` covers open, resolved by update, by dismissal, by withdrawal, an unreached app kept open, the `tracking started` first run, and `server_update` on a changed server version.

## 2. History filters and export

- [ ] 2.1 Add `AuditEntryMapper::findBetween()` and the `from`, `to`, `securityOnly` and `operation` parameters on `GET /api/audit`, with the D4 join. Verify: `tests/unit/Controller/ApiTest.php` asserts a period cut, a security-only result naming the fixed advisory, and that an install with no following resolution is left out.
- [ ] 2.2 Add the period fields, the security filter and CSV and JSON export to `HistoryPanel.vue`. Verify: `src/components/HistoryPanel.spec.ts` covers the request parameters and the CSV columns, including the 5000-row notice.

## 3. Remediation report

- [ ] 3.1 Add `SecurityReportService` and `ReportController` (`GET /api/reports/security`) per D6, reading deadlines from the patch policy when it exists. Verify: `tests/unit/Service/Report/SecurityReportServiceTest.php` covers within and past the deadline, a dismissal as a resolution, `tracking started` left out of the median, no deadline set, and the trend cut at retention; `tests/unit/Controller/ReportControllerTest.php` asserts 403 for a non-admin.
- [ ] 3.2 Add `src/components/SecurityReportPanel.vue` on the History tab with the period picker, the per-severity table, the monthly trend table and CSV export. Verify: `src/components/SecurityReportPanel.spec.ts` renders the null share with the patch policy hint.
- [ ] 3.3 Add the new strings to `l10n/en.json` and `l10n/nl.json`. Verify: `npm run check:l10n-js`.
- [ ] 3.4 Extend `tests/e2e/audit.spec.ts`: with a fixture advisory affecting `fixtureapp` 1.0.0, `runJob("AdvisoryRefreshJob")`, install 1.0.1, run the check again, then filter the History tab on today and "Security updates only" and see the 1.0.1 install naming the advisory; open the security report for this month and see one resolved advisory by update, and, with no patch policy recorded, the within-deadline share shown as unavailable with the patch policy hint. Verify: the spec passes in the Playwright job.

## 4. Close

- [ ] 4.1 Run the PHPUnit and Playwright jobs on the stable32, stable33 and stable34 legs of `nextcloud-test-refs`. Verify: every leg is green.
- [ ] 4.2 Set the matrix rows `aud-security-update-report` and `aud-remediation-kpi` to `built` with evidence lines, then archive this change.
