// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

import { expect, request as playwrightRequest, test } from "@playwright/test";
import {
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	fixtureControl,
	installFixture,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
	runJob,
	sqlExec,
} from "./helpers.ts";

/**
 * Security updates in the history and the remediation report
 * (audit-security-reports 3.4), driven against the fixture forge.
 *
 * A fixture advisory affects fixtureapp 1.0.0 and is fixed in 1.0.1. The
 * advisory check opens the exposure, the 1.0.1 install fixes it, the next
 * check records the resolution, and both the History tab's "Security updates
 * only" filter and the security report then name that fix.
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 * @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
 */

/** The fixture advisory: affects every fixtureapp before 1.0.1. */
const ADVISORY_ID = "GHSA-e2e0-audt-0001";
const ADVISORY = {
	ghsa_id: ADVISORY_ID,
	severity: "low",
	summary: "Fixture advisory for the security report",
	published_at: "2026-01-01T00:00:00Z",
	vulnerabilities: [
		{
			package: { ecosystem: "other", name: FIXTURE_APP },
			vulnerable_version_range: "< 1.0.1",
			first_patched_version: "1.0.1",
		},
	],
};

/** Today in the browser's time zone, as the date inputs read it. */
function today(): string {
	const now = new Date();
	const pad = (n: number) => String(n).padStart(2, "0");
	return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** The first day of this month in the browser's time zone. */
function firstOfMonth(): string {
	return `${today().slice(0, 8)}01`;
}

/** Sets a date input and lets the panel's watcher reload. */
async function setDate(page: Page, testId: string, value: string) {
	await page.getByTestId(testId).fill(value);
}

test.describe("security updates in the history and the report", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
		// Start from no open exposure and no earlier run of this scenario, so
		// the counts below belong to this test alone. The tracker's store is
		// set to an empty map (not deleted): a deleted store is a first run,
		// which records "tracking started" instead of a real opening.
		await sqlExec(
			`DELETE FROM oc_app_versions_audit WHERE app_id = '${FIXTURE_APP}' AND operation IN ('advisory_open', 'advisory_resolved')`,
		);
		await occ("config:app:set", "versioniq", "advisory.exposures", "--value={}");
		// No patch policy recorded: the within-deadline share is unavailable.
		await occ("config:app:delete", "versioniq", "patch_policy");
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			advisories: [ADVISORY],
		});
	});

	test.afterEach(async ({ page }) => {
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			advisories: [],
		}).catch(() => undefined);
	});

	// @e2e audit::the-security-filter-and-report-name-the-fix
	test("an update that fixes an advisory is named in the history and counted in the report", async ({
		page,
	}) => {
		// The check sees fixtureapp 1.0.0 affected and opens the exposure.
		await runJob("AdvisoryRefreshJob");

		const installed = await installFixture(page, "1.0.1");
		expect(installed.status, JSON.stringify(installed.body)).toBe(0);

		// The next check sees 1.0.1 no longer affected and records the fix.
		await runJob("AdvisoryRefreshJob");

		await openSettings(page);
		const history = await openTab(page, "History");

		await setDate(page, "history-from", today());
		await setDate(page, "history-to", today());
		await history.getByTestId("history-security-only").check();

		const install = history
			.locator(
				`[data-testid="history-row"][data-operation="install"][data-app-id="${FIXTURE_APP}"]`,
			)
			.first();
		await expect(install).toBeVisible();
		await expect(install).toContainText("1.0.1");
		await expect(install.getByTestId("history-fixed")).toContainText(ADVISORY_ID);

		// The security report for this month: one low advisory fixed by update.
		const report = page.getByTestId("security-report");
		await setDate(page, "report-from", firstOfMonth());
		await setDate(page, "report-to", today());

		const low = report
			.getByTestId("report-severities")
			.locator('tr[data-severity="low"]');
		await expect(low).toBeVisible();
		// The row header is the severity; the cells are opened, fixed by update, …
		await expect(low.locator("td").nth(1)).toHaveText("1");

		// With no patch policy recorded there is no deadline, so the share
		// fixed within it is shown as unavailable with the patch policy hint.
		await expect(low.locator('td[data-cell="within"]')).toHaveText("—");
		await expect(report.getByTestId("report-deadline-hint")).toContainText(
			"Set deadlines in the patch policy",
		);
	});

	// @e2e audit::a-non-admin-cannot-read-the-security-report
	test("a non-admin gets 403 from the security report", async ({ baseURL }) => {
		await occ("user:delete", "report-e2e-user");
		await execInInstance(
			["php", "occ", "user:add", "--password-from-env", "report-e2e-user"],
			{ env: { OC_PASS: "reportUserPass123" } },
		);
		// An empty storage state: the context must not carry the admin session.
		const context = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: {
				username: "report-e2e-user",
				password: "reportUserPass123",
			},
		});
		try {
			const response = await context.get(
				`/ocs/v2.php/apps/versioniq/api/reports/security?from=${firstOfMonth()}&to=${today()}&format=json`,
				{ headers: { "OCS-APIRequest": "true" } },
			);
			expect(response.status()).toBe(403);
			expect(await response.text()).not.toContain(ADVISORY_ID);
		} finally {
			await context.dispose();
			await occ("user:delete", "report-e2e-user");
		}
	});
});
