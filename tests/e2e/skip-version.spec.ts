// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Locator, Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import { NC_ADMIN_PASS } from "../../playwright.config";
import {
	appConfigValue,
	chooseApp,
	FIXTURE_APP,
	fixtureAvailable,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
	sql,
	versionsLoaded,
} from "./helpers.ts";

/**
 * Skipping one version (pinning-ranges-and-skips, task 2.3), driven against
 * the fixture forge. The fixture app is installed at 1.0.0 and the forge lists
 * 1.0.1, 1.1.0 and 1.2.0, so the sweep offers 1.2.0. Skipping 1.2.0 from the
 * update badge makes the next sweep offer 1.1.0, and the version picker marks
 * 1.2.0 as skipped with its reason and an Unskip action.
 *
 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
 */

const REASON = "breaks the import";

function fixtureCard(page: Page): Locator {
	return page
		.locator("article")
		.filter({ has: page.getByText(FIXTURE_APP, { exact: true }) })
		.first();
}

/** Runs the availability sweep, as the background job does. */
async function sweep(): Promise<void> {
	await occ("versioniq:updates", "--refresh");
}

/** Confirms the admin password when Nextcloud asks for it: skip and unskip are password-confirmed. */
async function confirmPasswordIfAsked(page: Page): Promise<void> {
	const field = page.locator('[role="dialog"] input[type="password"]').first();
	if (await field.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
		await field.fill(NC_ADMIN_PASS);
		await field.press("Enter");
		await expect(field).toBeHidden();
	}
}

test.describe("skip a version", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		test.setTimeout(180_000);
		await occ("config:app:delete", "versioniq", `skip.${FIXTURE_APP}`);
		await resetFixtureApp(page);
	});

	test.afterEach(async () => {
		await occ("config:app:delete", "versioniq", `skip.${FIXTURE_APP}`);
	});

	// @e2e version-pinning::a-bad-release-is-skipped-and-the-fix-still-arrives
	test("skipping the offered version from the badge offers the next one", async ({ page }) => {
		await sweep();
		await openSettings(page);
		await openTab(page, "Apps");
		const card = fixtureCard(page);
		await expect(card.getByTestId("update-badge")).toHaveText("Update available: 1.2.0");

		// --- Skip from the update badge ---------------------------------------
		const control = card.getByTestId("update-badge-skip");
		await control.getByRole("button", { name: "Skip this version" }).click();
		await control.getByLabel("Why skip 1.2.0? (optional)").fill(REASON);
		await control.getByRole("button", { name: "Skip 1.2.0", exact: true }).click();
		await confirmPasswordIfAsked(page);
		await expect(control.getByTestId("version-skipped-badge")).toContainText(REASON);
		await expect(control.getByRole("button", { name: "Unskip" })).toBeVisible();

		const stored = JSON.parse((await appConfigValue(page, `skip.${FIXTURE_APP}`)) ?? "[]");
		expect(stored.map((entry: { version: string }) => entry.version)).toEqual(["1.2.0"]);
		expect(stored[0].reason).toBe(REASON);
		expect(stored[0].skippedBy).toBe("admin");
		expect(
			await sql(`SELECT to_version FROM oc_app_versions_audit WHERE app_id = '${FIXTURE_APP}' AND operation = 'skip_version' ORDER BY id DESC LIMIT 1`),
			"the skip is audited",
		).toBe("1.2.0");

		// --- The next sweep offers the version after it -----------------------
		await sweep();
		await openSettings(page);
		await openTab(page, "Apps");
		await expect(fixtureCard(page).getByTestId("update-badge")).toHaveText("Update available: 1.1.0");

		// --- The picker marks the skipped version and can undo it -------------
		await chooseApp(page, FIXTURE_APP);
		expect(await versionsLoaded(page), "the fixture versions load").toBe(true);
		const row = page.getByTestId("version-skip-1.2.0");
		await expect(row.getByTestId("version-skipped-badge")).toContainText(REASON);
		await row.getByRole("button", { name: "Unskip" }).click();
		await confirmPasswordIfAsked(page);
		await expect(row.getByRole("button", { name: "Skip this version" })).toBeVisible();

		await sweep();
		await openSettings(page);
		await openTab(page, "Apps");
		await expect(fixtureCard(page).getByTestId("update-badge")).toHaveText("Update available: 1.2.0");
	});
});
