// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import {
	awaitWebInstanceSettled,
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
	sql,
} from "./helpers.ts";

const SECOND_APP = "fixtureapp2";
const SECOND_SOURCE = "forgejo:fixtureowner/fixtureapp2";
const APPS = [FIXTURE_APP, SECOND_APP];

async function installedVersion(appId: string): Promise<string> {
	return (await occ("config:app:get", appId, "installed_version")).trim();
}

/** Puts the second fixture app back on its 1.0.0 baseline. */
async function resetSecondApp(): Promise<void> {
	if ((await installedVersion(SECOND_APP)) === "1.0.0") {
		return;
	}
	await execInInstance([
		"php",
		"occ",
		"versioniq:install",
		SECOND_APP,
		"1.0.0",
		`--source=${SECOND_SOURCE}`,
		"--allow-downgrade",
		"--json",
	]);
	await occ("maintenance:mode", "--off");
}

/**
 * Update all: two fixture apps with a newer release on the forge fixture are
 * updated in one run from the Apps tab, and each install is in the History.
 *
 * @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md
 */
test.describe("update all", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		test.setTimeout(300_000);
		await resetFixtureApp(page);
		await resetSecondApp();
		expect(await installedVersion(SECOND_APP), "fixtureapp2 baseline").toBe(
			"1.0.0",
		);
		const refresh = await execInInstance([
			"php",
			"occ",
			"versioniq:updates",
			"--refresh",
		]);
		expect(refresh.code, refresh.stdout + refresh.stderr).toBe(0);
	});

	test.afterEach(async ({ page }) => {
		await resetSecondApp();
		await resetFixtureApp(page);
	});

	test("updates both fixture apps in one run and records two installs", async ({
		page,
	}) => {
		const lastAuditId = Number(
			(await sql("SELECT COALESCE(MAX(id), 0) FROM oc_app_versions_audit")) ||
				"0",
		);
		await openSettings(page);
		await openTab(page, "Apps");
		await page.getByTestId("update-all-button").click();

		const dialog = page.getByRole("dialog", { name: "Update all apps" });
		await expect(dialog).toBeVisible();
		for (const appId of APPS) {
			await expect(dialog.getByTestId(`update-all-row-${appId}`)).toContainText(
				`${appId}: 1.0.0 to 1.2.0`,
			);
		}
		// Only the two fixture apps take part; anything else stays as it is.
		const rows = dialog.locator('[data-testid^="update-all-row-"]');
		for (let i = 0; i < (await rows.count()); i++) {
			const row = rows.nth(i);
			const id = ((await row.getAttribute("data-testid")) ?? "").replace(
				"update-all-row-",
				"",
			);
			await row.getByRole("checkbox").setChecked(APPS.includes(id));
		}

		await dialog.getByRole("button", { name: "Update 2 apps" }).click();
		for (const appId of APPS) {
			await expect(
				dialog
					.getByTestId(`update-all-row-${appId}`)
					.getByTestId("update-all-status"),
			).toHaveText("Updated to 1.2.0", { timeout: 180_000 });
		}
		await dialog.locator("button").filter({ hasText: "Close" }).click();
		await awaitWebInstanceSettled(page);

		for (const appId of APPS) {
			expect(await installedVersion(appId), appId).toBe("1.2.0");
		}
		const installs = await sql(
			`SELECT app_id FROM oc_app_versions_audit WHERE id > ${lastAuditId} AND operation = 'install' AND to_version = '1.2.0' AND status = 'success' ORDER BY id`,
		);
		expect(installs.split("\n").map((line) => line.trim())).toEqual(APPS);

		await openSettings(page);
		const history = await openTab(page, "History");
		for (const appId of APPS) {
			await expect(
				history
					.getByRole("row", {
						name: new RegExp(` ${appId} install 1\\.0\\.0 → 1\\.2\\.0 `),
					})
					.first(),
			).toBeVisible();
		}
	});
});
