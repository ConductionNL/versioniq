// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Locator, Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import { NC_ADMIN_PASS } from "../../playwright.config";
import {
	FIXTURE_APP,
	FIXTURE_APP2,
	fixtureAvailable,
	fixtureControl,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
	sql,
} from "./helpers.ts";

/**
 * Replacing an app with its successor (inventory-lifecycle-signals, task 3.2),
 * driven against the fixture forge. A custom successor entry names the second
 * fixture app as the successor of the first. The successor is installed at
 * 1.0.0 and disabled; the forge lists it up to 1.2.0, so the sweep offers
 * "Replace with fixtureapp2" on the fixtureapp card. Replacing installs
 * fixtureapp2 1.2.0 first and only then disables fixtureapp; a successor
 * download that fails its checksum leaves fixtureapp enabled.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 */

const SUCCESSOR_SOURCE = `forgejo:fixtureowner/${FIXTURE_APP2}`;

function card(page: Page, appId: string): Locator {
	return page
		.locator("article")
		.filter({ has: page.getByText(appId, { exact: true }) })
		.first();
}

/** Whether an app is enabled, as the server records it. */
async function enabled(appId: string): Promise<string> {
	return (await occ("config:app:get", appId, "enabled")).trim();
}

/** The installed version of an app, as the server records it. */
async function installedVersion(appId: string): Promise<string> {
	return (await occ("config:app:get", appId, "installed_version")).trim();
}

/** The highest audit row id, so a test reads only the rows it wrote. */
async function lastAuditId(): Promise<number> {
	return Number((await sql("SELECT COALESCE(MAX(id), 0) FROM oc_app_versions_audit")) || 0);
}

/** Confirms the admin password when Nextcloud asks for it: replacing is password-confirmed. */
async function confirmPasswordIfAsked(page: Page): Promise<void> {
	const field = page.locator('[role="dialog"] input[type="password"]').first();
	if (await field.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
		await field.fill(NC_ADMIN_PASS);
		await field.press("Enter");
	}
}

/** Opens the replace dialog from the fixtureapp card and confirms it. */
async function replaceFromCard(page: Page): Promise<Locator> {
	await openSettings(page);
	await openTab(page, "Apps");
	const button = card(page, FIXTURE_APP).getByTestId("replace-successor-button");
	await expect(button).toHaveText(`Replace with ${FIXTURE_APP2}`);
	await button.click();

	const dialog = page.getByRole("dialog").filter({ has: page.getByTestId("replace-summary") });
	await expect(dialog.getByTestId("replace-summary")).toContainText(`${FIXTURE_APP2} 1.2.0`);
	await expect(dialog.getByTestId("replace-data-note")).toContainText(`The data of ${FIXTURE_APP} is kept`);
	await dialog.getByRole("button", { name: `Replace with ${FIXTURE_APP2}`, exact: true }).click();
	await confirmPasswordIfAsked(page);

	return dialog;
}

test.describe("replace an app with its successor", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		test.setTimeout(240_000);
		await resetFixtureApp(page, FIXTURE_APP2);
		await resetFixtureApp(page, FIXTURE_APP);
		await occ("app:disable", FIXTURE_APP2);
		await occ(
			"config:app:set",
			"versioniq",
			"successors.custom",
			"--value",
			JSON.stringify([{ from: FIXTURE_APP, to: FIXTURE_APP2, source: SUCCESSOR_SOURCE }]),
		);
		await occ("versioniq:updates", "--refresh");
	});

	test.afterEach(async ({ page }) => {
		await occ("config:app:delete", "versioniq", "successors.custom");
		await occ("app:enable", FIXTURE_APP);
		await resetFixtureApp(page, FIXTURE_APP2);
	});

	// @e2e lifecycle-signals::an-admin-moves-to-a-renamed-app
	test("installs the successor, then disables the old app, with two audit rows", async ({ page }) => {
		const before = await lastAuditId();

		const dialog = await replaceFromCard(page);
		await expect(dialog).toBeHidden({ timeout: 120_000 });

		await expect.poll(() => installedVersion(FIXTURE_APP2), { timeout: 60_000 }).toBe("1.2.0");
		expect(await enabled(FIXTURE_APP2)).toBe("yes");
		expect(await enabled(FIXTURE_APP)).toBe("no");

		const rows = (await sql(
			`SELECT app_id, operation, status FROM oc_app_versions_audit WHERE id > ${before} AND operation IN ('install', 'replace') ORDER BY id`,
		)).split("\n").map((line) => line.trim().split("\t"));
		expect(rows).toEqual([
			[FIXTURE_APP2, "install", "success"],
			[FIXTURE_APP, "replace", "success"],
		]);
	});

	// @e2e lifecycle-signals::a-failed-successor-install-leaves-the-old-app-running
	test("a successor download that fails its checksum leaves the old app enabled", async ({ page }) => {
		await fixtureControl(page, "asset", {
			asset: `${FIXTURE_APP2}-1.2.0.tar.gz`,
			serveInstead: `${FIXTURE_APP2}-1.0.0.tar.gz`,
		});
		const before = await lastAuditId();

		const dialog = await replaceFromCard(page);
		await expect(dialog.getByTestId("replace-error")).toContainText(`${FIXTURE_APP} stays enabled`, { timeout: 120_000 });

		expect(await enabled(FIXTURE_APP)).toBe("yes");
		expect(await installedVersion(FIXTURE_APP2)).toBe("1.0.0");
		expect(
			await sql(`SELECT COUNT(*) FROM oc_app_versions_audit WHERE id > ${before} AND operation = 'replace'`),
			"no replace row without a successful install",
		).toBe("0");
	});
});
