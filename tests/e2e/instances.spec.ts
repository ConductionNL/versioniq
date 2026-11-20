// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, request as playwrightRequest, test } from "@playwright/test";
import {
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
} from "./helpers.ts";

/**
 * The instance manifest and the Instances tab (install-multi-instance-promotion,
 * task 4.4).
 *
 * This instance's manifest is read as an admin and refused to a non-admin, and
 * the Instances tab shows this instance's column with the version the manifest
 * names for the fixture app. A second instance is not available in the e2e
 * setup and connections accept https only, so reading a remote and promoting
 * from it are covered by RemoteInstanceReaderTest and PromotionServiceTest.
 *
 * @e2e multi-instance::acceptance-reports-what-it-runs
 * @e2e multi-instance::a-non-admin-cannot-read-the-manifest
 */

const MANIFEST = "/ocs/v2.php/apps/versioniq/api/instance/manifest?format=json";
const USER = "instances-e2e-user";
const PASS = "instancesUserPass123";

test.describe("instances", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
	});

	// @e2e multi-instance::acceptance-reports-what-it-runs
	test("an admin reads what this instance runs, and the Instances tab shows it", async ({ page }) => {
		const response = await page.request.get(MANIFEST, { headers: { "OCS-APIRequest": "true" } });
		expect(response.status()).toBe(200);
		const manifest = (await response.json()).ocs.data;
		expect(typeof manifest.instanceName).toBe("string");
		expect(typeof manifest.versioniqVersion).toBe("string");
		const fixture = (manifest.apps as Array<{ appId?: string; id?: string; installedVersion?: string }>).find(
			(app) => (app.appId ?? app.id) === FIXTURE_APP,
		);
		expect(fixture, "the manifest lists the fixture app").toBeTruthy();
		expect(fixture?.installedVersion).toBe("1.0.0");

		await openSettings(page);
		const panel = await openTab(page, "Instances");
		await expect(panel.getByTestId("instances-column-self")).toBeVisible();
		await expect(panel.getByTestId("instances-column-self")).toContainText("this instance");
		const row = panel.getByTestId(`instances-row-${FIXTURE_APP}`);
		await expect(row).toBeVisible();
		await expect(row.getByTestId("instances-cell-version").first()).toHaveText(fixture?.installedVersion ?? "");
	});

	// @e2e multi-instance::a-non-admin-cannot-read-the-manifest
	test("a non-admin gets 403 and no app data", async ({ baseURL }) => {
		await occ("user:delete", USER);
		await execInInstance(["php", "occ", "user:add", "--password-from-env", USER], { env: { OC_PASS: PASS } });
		// Unset explicitly: otherwise the context inherits the project's admin session.
		const context = await playwrightRequest.newContext({
			baseURL,
			storageState: undefined,
			httpCredentials: { username: USER, password: PASS },
		});
		try {
			const response = await context.get(MANIFEST, { headers: { "OCS-APIRequest": "true" } });
			expect(response.status()).toBe(403);
			expect(await response.text()).not.toContain(FIXTURE_APP);
		} finally {
			await context.dispose();
			await occ("user:delete", USER);
		}
	});
});
