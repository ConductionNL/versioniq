// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import {
	appConfigValue,
	FIXTURE_APP,
	FIXTURE_APP2,
	fixtureAvailable,
	fixtureControl,
	occ,
	openSettings,
	openTab,
	resetFixture,
	resetFixtureApp,
	runJob,
} from "./helpers.ts";

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

/**
 * Update groups and run limits (autoupdate-run-batching 4.3), set through the
 * UI. Only apps with an auto-update policy can be grouped, so both fixture
 * apps get a patch policy first.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
test.describe("update groups and run limits", () => {
	const OCS = { "OCS-APIRequest": "true", "Content-Type": "application/json" };

	async function policy(page: Page, appId: string, level: string | null) {
		const url = `/ocs/v2.php/apps/versioniq/api/app/${appId}/policy?format=json`;
		if (level === null) {
			await page.request.delete(url, { headers: OCS }).catch(() => undefined);
			return;
		}
		const res = await page.request.put(url, { headers: OCS, data: { level } });
		expect(res.ok(), `PUT policy ${appId}: ${res.status()}`).toBe(true);
	}

	async function clearGroupsAndLimits(page: Page) {
		await page.request
			.put("/ocs/v2.php/apps/versioniq/api/auto-update/groups?format=json", {
				headers: OCS,
				data: { groups: [] },
			})
			.catch(() => undefined);
		await occ("config:app:delete", "versioniq", "auto_update.max_per_run");
		await occ("config:app:delete", "versioniq", "auto_update.max_per_week");
	}

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await clearGroupsAndLimits(page);
		await policy(page, FIXTURE_APP, "patch");
		await policy(page, FIXTURE_APP2, "patch");
	});

	test.afterEach(async ({ page }) => {
		await clearGroupsAndLimits(page);
		await policy(page, FIXTURE_APP, null);
		await policy(page, FIXTURE_APP2, null);
	});

	// @e2e auto-update-policies::an-admin-groups-two-apps-and-limits-a-run
	test("an admin creates a group of two apps and a per-run limit of 3, and both survive a reload", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");
		const panel = page.getByTestId("update-groups-panel");
		await expect(panel).toBeVisible();
		await expect(panel.getByTestId("update-groups-none")).toBeVisible();

		await panel.getByTestId("update-group-new-name").fill("Fixture pair");
		const select = panel.getByTestId("update-group-new-apps");
		for (const label of ["Forge Fixture App", "Forge Fixture App Two"]) {
			await select.locator("input").click();
			await select.locator("input").fill(label);
			await page.getByRole("option", { name: label, exact: true }).click();
		}
		await panel.getByTestId("update-group-add").click();
		await panel.getByTestId("update-groups-save").click();
		await expect
			.poll(async () => appConfigValue(page, "auto_update.groups"))
			.toContain(FIXTURE_APP2);

		await panel.getByTestId("update-limit-run").fill("3");
		await panel.getByTestId("update-limits-save").click();
		await expect
			.poll(async () => appConfigValue(page, "auto_update.max_per_run"))
			.toBe("3");

		await page.reload({ waitUntil: "domcontentloaded" });
		await openTab(page, "Apps");
		const reloaded = page.getByTestId("update-groups-panel");
		const group = reloaded.getByTestId("update-group");
		await expect(group).toHaveCount(1);
		await expect(group.getByTestId("update-group-name")).toHaveText("Fixture pair");
		const members = group.getByTestId("update-group-app").locator("span");
		await expect(members).toHaveText(["Forge Fixture App", "Forge Fixture App Two"]);
		await expect(reloaded.getByTestId("update-limit-run")).toHaveValue("3");
	});
});

/**
 * The minimum age: a release younger than the minimum age is held back, the
 * plan job works out the next update from the versions old enough, and the
 * overview names the held version with the day it may install.
 *
 * The forge fixture serves fixtureapp 1.0.1 released ten days ago and 1.1.0
 * released two days ago; with a minimum age of seven days and level `minor`,
 * the next update is 1.0.1 and 1.1.0 waits until five days from now.
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 */
test.describe("the minimum age in the plan", () => {
	const DAY = 24 * 60 * 60 * 1000;
	const iso = (daysAgo: number) => new Date(Date.now() - daysAgo * DAY).toISOString().replace(/\.\d{3}Z$/, "Z");
	const OCS = { "OCS-APIRequest": "true", "Content-Type": "application/json" };
	const policyUrl = `/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/policy?format=json`;

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			releases: [
				{ tag: "v1.1.0", asset: "fixtureapp-1.1.0.tar.gz", sha: true, published_at: iso(2) },
				{ tag: "v1.0.1", asset: "fixtureapp-1.0.1.tar.gz", sha: true, published_at: iso(10) },
				{ tag: "v1.0.0", asset: "fixtureapp-1.0.0.tar.gz", sha: true, published_at: iso(30) },
			],
		});
		await occ("config:app:set", "versioniq", "auto_update_min_age_days", "--value", "7");
		const res = await page.request.put(policyUrl, { headers: OCS, data: { level: "minor" } });
		expect(res.ok(), "the minor policy is saved").toBe(true);
	});

	test.afterEach(async ({ page }) => {
		await page.request.delete(policyUrl, { headers: OCS }).catch(() => undefined);
		await occ("config:app:delete", "versioniq", "auto_update_min_age_days");
		await occ("config:app:delete", "versioniq", "autoupdate.plan");
		await occ("config:app:delete", "versioniq", "autoupdate.plan.checkedAt");
		await resetFixture(page);
	});

	// @e2e releases-minimum-age::the-overview-names-the-held-version-and-its-date
	test("after the plan job, the overview shows the next update and the held version with its date", async ({
		page,
	}) => {
		await runJob("AutoUpdatePlanJob");

		const res = await page.request.get("/ocs/v2.php/apps/versioniq/api/policies?format=json", { headers: OCS });
		expect(res.ok()).toBe(true);
		const data = (await res.json())?.ocs?.data;
		expect(data.planCheckedAt, "the plan job recorded when it ran").toBeTruthy();
		const entry = (data.policies ?? []).find((p: any) => p.appId === FIXTURE_APP);
		expect(entry?.plan, JSON.stringify(entry)).toBeTruthy();
		expect(entry.plan.next).toBe("1.0.1");
		const held = (entry.plan.heldBack ?? []).find((h: any) => h.version === "1.1.0");
		expect(held, JSON.stringify(entry.plan)).toBeTruthy();
		// Eligible seven days after its release, so five days from now (give or take the clock).
		const eligibleIn = new Date(held.eligibleAt).getTime() - Date.now();
		expect(eligibleIn).toBeGreaterThan(4 * DAY);
		expect(eligibleIn).toBeLessThan(6 * DAY);

		await openSettings(page);
		await openTab(page, "Apps");
		// The row shows the app's name when the page knows it, else its id.
		const row = page
			.getByTestId("overview-scheduled-row")
			.filter({ hasText: /Forge Fixture App(?! Two)|fixtureapp(?!2)/ });
		await expect(row.getByTestId("overview-next")).toHaveText("Next update: 1.0.1");
		const date = await page.evaluate((at) => new Date(at).toLocaleDateString(), held.eligibleAt);
		await expect(row.getByTestId("overview-held-back")).toContainText(
			`1.1.0 held back until ${date} (minimum age 7 days)`,
		);
		await expect(row.getByTestId("overview-held-back").getByRole("button", { name: "Release now" })).toBeVisible();
		await expect(page.getByTestId("overview-plan-checked")).toContainText("Next updates worked out at");
	});

	test("the plan job changes no version", async () => {
		await runJob("AutoUpdatePlanJob");
		expect((await occ("config:app:get", FIXTURE_APP, "installed_version")).trim()).toBe("1.0.0");
	});
});
