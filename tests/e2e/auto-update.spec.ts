import { expect, test } from "@playwright/test";
import { appConfigValue, openSettings, openTab } from "./helpers.ts";

/**
 * Per-app auto-update policies and the global kill switch / window.
 * @spec openspec/specs/auto-update-policies/spec.md
 */
const APP = "dashboard";

test.describe("auto-update policies", () => {
	test.afterEach(async ({ page }) => {
		await page.request
			.delete(
				`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
				{
					headers: { "OCS-APIRequest": "true" },
				},
			)
			.catch(() => undefined);
	});

	test("automation is off by default and says so", async ({ page }) => {
		await openSettings(page);
		await openTab(page, "Apps");

		const settings = page.getByTestId("auto-update-settings");
		await expect(settings).toBeVisible();
		await expect(
			page.getByTestId("auto-update-kill-switch"),
		).not.toBeChecked();
		// Default window is advertised.
		await expect(page.getByTestId("auto-update-window")).toHaveValue(
			/01:00-05:00/,
		);
	});

	test("an invalid maintenance window is rejected before saving", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");

		await page.getByTestId("auto-update-window").fill("nonsense");
		await expect(
			page.getByTestId("auto-update-window-error"),
		).toBeVisible();
		await expect(
			page.getByTestId("auto-update-settings-save"),
		).toBeDisabled();
	});

	test("enabling automation with a valid window persists server-side", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");

		await page.getByTestId("auto-update-kill-switch").check();
		await page.getByTestId("auto-update-window").fill("23:00-03:00");
		await page.getByTestId("auto-update-settings-save").click();

		await expect
			.poll(async () => appConfigValue(page, "auto_update_enabled"), {
				message: "kill switch should persist",
				timeout: 20_000,
			})
			.toBe("1");
		expect(await appConfigValue(page, "auto_update_window")).toBe(
			"23:00-03:00",
		);

		// Put the instance back the way we found it.
		await page.getByTestId("auto-update-kill-switch").uncheck();
		await page.getByTestId("auto-update-window").fill("01:00-05:00");
		await page.getByTestId("auto-update-settings-save").click();
		await expect
			.poll(async () => appConfigValue(page, "auto_update_enabled"), {
				timeout: 20_000,
			})
			.not.toBe("1");
	});

	test("a per-app policy is persisted and badged", async ({ page }) => {
		await openSettings(page);
		await openTab(page, "Apps");

		const card = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		const selector = card.getByTestId("policy-select");
		await expect(selector).toBeVisible();

		// NcSelect renders a combobox; pick the "Patch" option.
		await selector.click();
		await page.getByRole("option", { name: /patch/i }).first().click();

		await expect
			.poll(async () => appConfigValue(page, `policy.${APP}`), {
				message: "policy should persist",
				timeout: 20_000,
			})
			.toContain("patch");

		await expect(card.getByTestId("policy-active-badge")).toBeVisible();
	});

	test("policies show as inert while the kill switch is off", async ({
		page,
	}) => {
		// Seed a policy directly, then confirm the UI explains it will not run.
		await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { level: "patch" },
			},
		);

		await openSettings(page);
		await openTab(page, "Apps");

		const card = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		await expect(card.getByTestId("policy-disabled-hint")).toBeVisible();
	});
});
