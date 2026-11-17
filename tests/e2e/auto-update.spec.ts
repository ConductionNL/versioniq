// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import { appConfigValue, occ, openSettings, openTab } from "./helpers.ts";

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

	// @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	test("weekdays Mon to Fri for every kind persist and show the next run", async ({
		page,
	}) => {
		try {
			await occ("config:app:set", "versioniq", "auto_update_enabled", "--value", "1");
			await page.request.put(
				`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
				{
					headers: {
						"OCS-APIRequest": "true",
						"Content-Type": "application/json",
					},
					data: { level: "all" },
				},
			);

			await openSettings(page);
			await openTab(page, "Apps");
			await page.getByTestId("schedule-mode-same").check();
			for (const day of [1, 2, 3, 4, 5]) {
				await page.getByTestId(`schedule-day-all-${day}`).check();
			}
			for (const day of [6, 7]) {
				await page.getByTestId(`schedule-day-all-${day}`).uncheck();
			}
			await page.getByTestId("auto-update-settings-save").click();
			await expect
				.poll(
					async () =>
						JSON.parse(
							(await appConfigValue(page, "auto_update_schedule")) ?? "{}",
						).patch?.days,
					{ message: "schedule should persist", timeout: 20_000 },
				)
				.toEqual([1, 2, 3, 4, 5]);

			await page.reload({ waitUntil: "domcontentloaded" });
			await openTab(page, "Apps");
			for (const day of [1, 2, 3, 4, 5]) {
				await expect(page.getByTestId(`schedule-day-all-${day}`)).toBeChecked();
			}
			for (const day of [6, 7]) {
				await expect(
					page.getByTestId(`schedule-day-all-${day}`),
				).not.toBeChecked();
			}
			for (const kind of ["patch", "minor", "major"]) {
				await expect(page.getByTestId(`overview-kind-${kind}`)).toContainText(
					"next run",
				);
			}
		} finally {
			await occ("config:app:delete", "versioniq", "auto_update_schedule");
			await occ("config:app:delete", "versioniq", "auto_update_enabled");
		}
	});

	// @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	test("a group of two apps and a per-run limit persist across a reload", async ({
		page,
	}) => {
		const members = ["fixtureapp", "fixtureapp2"];
		const policyUrl = (appId: string) =>
			`/ocs/v2.php/apps/versioniq/api/app/${appId}/policy?format=json`;
		try {
			for (const appId of members) {
				await page.request.put(policyUrl(appId), {
					headers: {
						"OCS-APIRequest": "true",
						"Content-Type": "application/json",
					},
					data: { level: "patch" },
				});
			}

			await openSettings(page);
			await openTab(page, "Apps");
			const panel = page.getByTestId("update-groups-panel");
			await panel.getByTestId("update-limit-run").fill("3");
			await panel.getByTestId("update-limits-save").click();
			await expect
				.poll(async () => appConfigValue(page, "auto_update.max_per_run"), {
					timeout: 20_000,
				})
				.toBe("3");

			await panel.getByTestId("update-group-new-name").fill("Fixture pair");
			const apps = panel.getByTestId("update-group-new-apps");
			for (const label of ["Forge Fixture App", "Forge Fixture App Two"]) {
				await apps.click();
				// NcSelect splits an option's text around its highlight, so match
				// the text content rather than the accessible name.
				await page
					.getByRole("option")
					.filter({ hasText: new RegExp(`^\\s*${label}\\s*$`) })
					.click();
			}
			await page.keyboard.press("Escape");
			await panel.getByTestId("update-group-add").click();
			await panel.getByTestId("update-groups-save").click();
			await expect
				.poll(async () => appConfigValue(page, "auto_update.groups"), {
					timeout: 20_000,
				})
				.toContain("fixtureapp2");

			await page.reload({ waitUntil: "domcontentloaded" });
			await openTab(page, "Apps");
			const reloaded = page.getByTestId("update-groups-panel");
			await expect(reloaded.getByTestId("update-limit-run")).toHaveValue("3");
			const group = reloaded
				.getByTestId("update-group")
				.filter({ hasText: "Fixture pair" });
			await expect(group.getByTestId("update-group-app")).toHaveText([
				"Forge Fixture App",
				"Forge Fixture App Two",
			].map((label) => new RegExp(`^\\s*${label}\\s*↑\\s*↓\\s*$`)));
		} finally {
			await occ("config:app:delete", "versioniq", "auto_update.groups");
			await occ("config:app:delete", "versioniq", "auto_update.max_per_run");
			for (const appId of members) {
				await page.request
					.delete(policyUrl(appId), { headers: { "OCS-APIRequest": "true" } })
					.catch(() => undefined);
			}
		}
	});
});
