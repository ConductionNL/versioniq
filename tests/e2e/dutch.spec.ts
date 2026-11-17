// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import { NC_ADMIN_PASS, NC_ADMIN_USER } from "../../playwright.config";
import {
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	occ,
	resetFixtureApp,
	SETTINGS_URL,
} from "./helpers.ts";

/**
 * The Apps tab in Dutch (admin-translate-apps-tab 3.1).
 *
 * The admin's language is set to `nl`, the Apps tab is opened, and the dry
 * run switch, the app filter and the install result grid must read in Dutch.
 * The shared helpers find their way by English labels, so this spec uses the
 * Dutch ones throughout: an English label anywhere on the path is itself the
 * failure being looked for. A full-page screenshot is attached so a reviewer
 * can see whether a longer Dutch string breaks a card layout (design, risks).
 *
 * @spec openspec/changes/admin-translate-apps-tab/specs/interface-language/spec.md
 */
test.describe("the Apps tab in Dutch", () => {
	let previousLanguage: string | null = null;

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);

		const current = await execInInstance([
			"php",
			"occ",
			"user:setting",
			NC_ADMIN_USER,
			"core",
			"lang",
		]);
		previousLanguage = current.code === 0 ? current.stdout.trim() || null : null;
		await occ("user:setting", NC_ADMIN_USER, "core", "lang", "nl");
	});

	test.afterEach(async () => {
		if (previousLanguage !== null) {
			await occ("user:setting", NC_ADMIN_USER, "core", "lang", previousLanguage);
		} else {
			await occ("user:setting", "--delete", NC_ADMIN_USER, "core", "lang");
		}
	});

	// @e2e admin-translate-apps-tab::the-apps-tab-reads-in-dutch
	test("an admin with language nl sees Dutch labels on the Apps tab and in the result grid", async ({
		page,
	}, testInfo) => {
		await page.goto(SETTINGS_URL, { waitUntil: "domcontentloaded" });
		const tabs = page.getByRole("tablist", { name: "Versioniq-secties" });
		await expect(tabs).toBeVisible();
		await tabs.getByRole("tab", { name: "Apps", exact: true }).click();

		// The app filter.
		await expect(page.locator("#app-filter")).toHaveAttribute(
			"placeholder",
			"Apps zoeken",
		);

		const card = page
			.locator("article")
			.filter({ has: page.getByText(FIXTURE_APP, { exact: true }) })
			.first();
		await card.getByRole("button", { name: "App kiezen" }).click();
		await expect(page.getByText("Gekozen app")).toBeVisible();

		// The dry run switch.
		const dryRun = page
			.locator("label")
			.filter({ hasText: "Proefinstallatie (installatie beoordelen, niets wijzigen)" });
		await expect(dryRun).toBeVisible();
		await dryRun.locator('input[type="checkbox"]').check();
		await expect(
			page.locator("label").filter({ hasText: "Installatiedetails tonen" }),
		).toBeVisible();

		// A dry run of 1.0.1 fills the result grid without changing anything.
		const version = page
			.locator("li")
			.filter({ has: page.getByText("1.0.1", { exact: true }) })
			.first();
		await version.getByRole("button", { name: "Kiezen", exact: true }).click();
		await page.getByRole("button", { name: "Bijwerken", exact: true }).click();
		// The install is password-confirmed; a session older than the
		// confirmation window is asked for it.
		const password = page.locator('[role="dialog"] input[type="password"]').first();
		if (await password.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
			await password.fill(NC_ADMIN_PASS);
			await password.press("Enter");
		}

		await expect(page.getByText("Installatieresultaat")).toBeVisible({
			timeout: 60_000,
		});
		for (const label of ["Overgang", "Modus", "Resultaat"]) {
			await expect(
				page.getByText(label, { exact: true }).first(),
			).toBeVisible();
		}
		await expect(
			page.getByText(/^Proefinstallatie( \(niets geschreven\))?$/).first(),
		).toBeVisible();

		// None of the English labels this change translated may still render.
		for (const english of [
			"Dry run (evaluate the install, apply no changes)",
			"Show install debug output",
			"Install result",
			"Transition",
		]) {
			await expect(page.getByText(english, { exact: true })).toHaveCount(0);
		}

		await testInfo.attach("apps-tab-nl", {
			body: await page.screenshot({ fullPage: true }),
			contentType: "image/png",
		});
	});
});
