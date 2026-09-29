import type { Page } from "@playwright/test";

import { expect, request as playwrightRequest, test } from "@playwright/test";
import {
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	fixtureControl,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
} from "./helpers.ts";

/**
 * Pending updates (inventory-pending-updates): the sweep, the card badges,
 * the lag limit and `occ versioniq:updates`, driven against the fixture forge.
 * The fixture app is installed at 1.0.0 and the forge lists 1.0.1, 1.1.0 and
 * 1.2.0, so after a sweep it is 1.2.0 available and two release lines behind.
 *
 * @spec openspec/specs/pending-updates/spec.md
 * @spec openspec/specs/cli-commands/spec.md
 */

async function updatesCli(
	...args: string[]
): Promise<{ code: number; stdout: string }> {
	const { code, stdout } = await execInInstance([
		"php",
		"occ",
		"versioniq:updates",
		...args,
	]);
	return { code, stdout };
}

function lastJson(out: string): any {
	const m = out.match(/\{[\s\S]*\}\s*$/);
	return m ? JSON.parse(m[0]) : {};
}

function fixtureCard(page: Page) {
	return page
		.locator("article")
		.filter({ has: page.getByText(FIXTURE_APP, { exact: true }) })
		.first();
}

test.describe("pending updates", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		test.setTimeout(180_000);
		await resetFixtureApp(page);
	});

	test.afterEach(async () => {
		await occ("config:app:delete", "versioniq", "update.max_lines_behind");
		await occ("config:app:delete", "versioniq", `pin.${FIXTURE_APP}`);
	});

	// @e2e pending-updates::a-newer-compatible-version-is-recorded
	// @e2e cli-commands::a-script-reads-pending-updates-as-json
	test("the sweep records the newest version and the release lines behind", async () => {
		const { code, stdout } = await updatesCli("--refresh", "--json");
		expect(code).toBe(0);
		const result = lastJson(stdout);
		expect(typeof result.checkedAt).toBe("number");
		const entry = result.updates[FIXTURE_APP];
		expect(entry.installedVersion).toBe("1.0.0");
		expect(entry.newestCompatibleVersion).toBe("1.2.0");
		expect(entry.linesBehind).toBe(2);
		expect(entry.updateAvailable).toBe(true);
	});

	// @e2e pending-updates::an-unreachable-source-is-not-read-as-up-to-date
	test("a source that refuses is recorded as not checked", async ({ page }) => {
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			status: 503,
		});
		const { stdout } = await updatesCli("--refresh", "--json");
		const entry = lastJson(stdout).updates[FIXTURE_APP];
		expect(entry.error).toBeTruthy();
		expect(entry.newestVersion).toBeNull();
		expect(entry.updateAvailable).toBe(false);
	});

	// @e2e pending-updates::an-admin-sees-which-apps-are-behind
	test("the card shows the installed version and the update", async ({
		page,
	}) => {
		await updatesCli("--refresh");
		await openSettings(page);
		await openTab(page, "Apps");
		const card = fixtureCard(page);
		await expect(card.getByTestId("app-installed-version")).toHaveText(
			"Installed 1.0.0",
		);
		await expect(card.getByTestId("update-badge")).toHaveText(
			"Update available: 1.2.0",
		);
		await expect(page.getByTestId("updates-freshness")).toContainText(
			"Updates checked",
		);
	});

	// @e2e pending-updates::a-pinned-app-still-shows-its-update
	test("a pinned app keeps its update badge", async ({ page }) => {
		await occ(
			"config:app:set",
			"versioniq",
			`pin.${FIXTURE_APP}`,
			"--value",
			JSON.stringify({
				version: "1.0.0",
				pinnedBy: "admin",
				pinnedAt: "2026-09-29T00:00:00+00:00",
			}),
		);
		await updatesCli("--refresh");
		await openSettings(page);
		await openTab(page, "Apps");
		await expect(fixtureCard(page).getByTestId("update-badge")).toHaveText(
			"Update available: 1.2.0, held by the pin",
		);
	});

	// @e2e pending-updates::no-sweep-has-run-yet
	// @e2e cli-commands::never-checked-is-not-reported-as-nothing-to-do
	test("before the first sweep nothing reads as up to date", async ({
		page,
	}) => {
		await occ("config:app:delete", "versioniq", "availability.results");
		await occ(
			"config:app:delete",
			"versioniq",
			"availability.results.checkedAt",
		);
		const { code, stdout } = await updatesCli();
		expect(code).toBe(1);
		expect(stdout).toContain("No update check has run yet");

		await openSettings(page);
		await openTab(page, "Apps");
		await expect(page.getByTestId("updates-freshness")).toHaveText(
			"Updates not checked yet. The background job runs every 6 hours.",
		);
		await expect(page.getByTestId("update-badge")).toHaveCount(0);
	});

	// @e2e pending-updates::an-app-outside-an-n-1-policy-is-flagged
	test("the filter lists apps past the lag limit", async ({ page }) => {
		await occ(
			"config:app:set",
			"versioniq",
			"update.max_lines_behind",
			"--value",
			"1",
		);
		await updatesCli("--refresh");
		await openSettings(page);
		const panel = await openTab(page, "Apps");
		await panel.getByRole("button", { name: "Show filters" }).click();
		await page.getByTestId("updates-filter").selectOption("outsidePolicy");
		const card = fixtureCard(page);
		await expect(card.getByTestId("update-policy-flag")).toHaveText(
			"Outside the update policy: 2 releases behind",
		);
		for (const flag of await page.getByTestId("update-policy-flag").all()) {
			await expect(flag).toContainText("Outside the update policy");
		}
	});

	// @e2e pending-updates::a-non-admin-cannot-read-the-snapshot
	test("a non-admin gets 403 and no app data", async ({ baseURL }) => {
		await occ("user:delete", "updates-e2e-user");
		await execInInstance(
			["php", "occ", "user:add", "--password-from-env", "updates-e2e-user"],
			{ env: { OC_PASS: "updatesUserPass123" } },
		);
		const context = await playwrightRequest.newContext({
			baseURL,
			httpCredentials: {
				username: "updates-e2e-user",
				password: "updatesUserPass123",
			},
		});
		try {
			const response = await context.get(
				"/ocs/v2.php/apps/versioniq/api/updates?format=json",
				{ headers: { "OCS-APIRequest": "true" } },
			);
			expect(response.status()).toBe(403);
			expect(await response.text()).not.toContain(FIXTURE_APP);
		} finally {
			await context.dispose();
			await occ("user:delete", "updates-e2e-user");
		}
	});
});
