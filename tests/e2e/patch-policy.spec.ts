// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import { NC_ADMIN_PASS, NC_ADMIN_USER } from "../../playwright.config";
import { appConfigValue, occ, openSettings, openTab } from "./helpers.ts";

/**
 * The patch policy and the "Security fixes only" level
 * (autoupdate-security-first 4.2), set through the UI.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
const APP = "dashboard";

test.describe("patch policy", () => {
	test.beforeEach(async () => {
		await occ("config:app:delete", "versioniq", "patch_policy");
	});

	test.afterEach(async ({ page }) => {
		await occ("config:app:delete", "versioniq", "patch_policy");
		await page.request
			.delete(`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`, {
				headers: { "OCS-APIRequest": "true" },
			})
			.catch(() => undefined);
	});

	// @e2e autoupdate-security-first::an-admin-records-a-patch-policy
	test("an admin saves a critical deadline of 3 days, reloads, and sees the sentence and their name", async ({
		page,
	}) => {
		await openSettings(page);
		const settings = await openTab(page, "Settings");
		const panel = settings.getByTestId("patch-policy-panel");
		await expect(panel.getByTestId("patch-policy-statement")).toBeVisible();

		await panel.getByTestId("patch-policy-handling-critical").selectOption("next_window");
		await panel.getByTestId("patch-policy-deadline-critical").fill("3");
		await panel.getByTestId("patch-policy-save").click();

		const password = page.locator('[role="dialog"] input[type="password"]').first();
		if (await password.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
			await password.fill(NC_ADMIN_PASS);
			await password.press("Enter");
		}
		await expect(panel.getByTestId("patch-policy-notice")).toBeVisible();
		await expect
			.poll(async () => appConfigValue(page, "patch_policy"))
			.toContain("next_window");

		await page.reload({ waitUntil: "domcontentloaded" });
		const reloaded = (await openTab(page, "Settings")).getByTestId("patch-policy-panel");
		const statement = reloaded.getByTestId("patch-policy-statement");
		await expect(statement).toContainText(
			"Critical security fixes: installed at the next update window. Deadline: 3 days.",
		);
		await expect(statement).toContainText(`Last changed by ${NC_ADMIN_USER} on`);
		await expect(reloaded.getByTestId("patch-policy-deadline-critical")).toHaveValue("3");
	});

	// @e2e autoupdate-security-first::an-app-takes-security-fixes-only
	test("an admin sets one app to Security fixes only", async ({ page }) => {
		await openSettings(page);
		await openTab(page, "Apps");

		const card = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		await card.getByTestId("policy-select").click();
		await page.getByRole("option", { name: "Security fixes only", exact: true }).click();

		await expect
			.poll(async () => appConfigValue(page, `policy.${APP}`), { timeout: 20_000 })
			.toContain("security");
		await expect(card.getByTestId("policy-active-badge")).toContainText("Security fixes only");
	});
});
