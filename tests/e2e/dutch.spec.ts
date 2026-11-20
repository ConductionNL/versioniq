// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import { NC_ADMIN_USER } from "../../playwright.config";
import {
	FIXTURE_APP,
	fixtureAvailable,
	occ,
	resetFixtureApp,
	SETTINGS_URL,
} from "./helpers.ts";

/**
 * A Dutch admin reads the Apps tab in Dutch: the dry run switch, the app
 * filter, the version filter and the result grid of a dry run.
 *
 * @spec openspec/changes/admin-translate-apps-tab/specs/interface-language/spec.md
 */
test.describe("the Apps tab in Dutch", () => {
	test.beforeAll(async () => {
		await occ("user:setting", NC_ADMIN_USER, "core", "lang", "nl");
	});

	test.afterAll(async () => {
		await occ("user:setting", NC_ADMIN_USER, "core", "lang", "en");
	});

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
	});

	// @e2e interface-language::a-dutch-admin-reads-the-apps-tab-in-dutch
	test("a Dutch admin sees the Apps tab and a dry run result in Dutch", async ({
		page,
	}, testInfo) => {
		await page.goto(SETTINGS_URL, { waitUntil: "domcontentloaded" });
		const tabs = page.getByRole("tablist", { name: "Versioniq-secties" });
		await expect(tabs).toBeVisible();
		await tabs.getByRole("tab", { name: "Apps", exact: true }).click();

		await expect(page.getByPlaceholder("Apps zoeken")).toBeVisible();
		const dryRun = page.getByRole("checkbox", {
			name: "Proefinstallatie (installatie beoordelen, niets wijzigen)",
		});
		await expect(dryRun).toBeVisible();
		await dryRun.check();

		const card = page
			.locator("article")
			.filter({ has: page.getByText(FIXTURE_APP, { exact: true }) })
			.first();
		await card.getByRole("button", { name: "App kiezen" }).click();
		await expect(page.getByText("Gekozen app")).toBeVisible();
		await expect(page.getByPlaceholder("Versies filteren")).toBeVisible({
			timeout: 45_000,
		});

		// The picker row: other lists on the page can name the same version.
		const row = page.locator("li", {
			has: page.locator("span", { hasText: /^1\.1\.0$/ }),
		});
		// The Select button shows on hover only.
		await row.hover();
		await row.locator("button", { hasText: "Kiezen" }).click();
		await row.locator("button", { hasText: "Bijwerken" }).click();

		await expect(page.getByText("Overgang", { exact: true })).toBeVisible({
			timeout: 45_000,
		});
		await expect(page.getByText("Modus", { exact: true })).toBeVisible();
		await expect(page.getByText("Resultaat", { exact: true })).toBeVisible();
		await expect(
			page.getByText("Proefinstallatie (niets geschreven)", { exact: true }),
		).toBeVisible();

		await testInfo.attach("apps-tab-nl", {
			body: await page.screenshot({ fullPage: true }),
			contentType: "image/png",
		});

		// The dry run changed nothing.
		expect(
			(
				await occ("config:app:get", FIXTURE_APP, "installed_version")
			).trim(),
		).toBe("1.0.0");
	});
});
