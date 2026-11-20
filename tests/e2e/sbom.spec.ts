// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Download } from "@playwright/test";

import { expect, request as playwrightRequest, test } from "@playwright/test";
import { readFile } from "node:fs/promises";
import {
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	installFixture,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
} from "./helpers.ts";

/**
 * SBOMs from the History tab (inventory-export-sbom-metrics 3.2).
 *
 * An install of a fixture version keeps an SBOM of the state it produced;
 * its History row offers it for download, and the file names the version the
 * install left. The Export SBOM menu downloads the current inventory in both
 * formats, and a non-admin gets 403.
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
 */

/** The JSON body of a finished download. */
async function json(download: Download): Promise<any> {
	const path = await download.path();
	expect(path, "the download was saved").toBeTruthy();
	return JSON.parse(await readFile(String(path), "utf8"));
}

test.describe("SBOM downloads", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
	});

	// @e2e inventory-export-sbom-metrics::an-install-row-downloads-its-sbom
	test("an install keeps an SBOM, and its History row downloads it", async ({ page }) => {
		const installed = await installFixture(page, "1.0.1");
		expect(installed.status, JSON.stringify(installed.body)).toBe(0);

		await openSettings(page);
		const history = await openTab(page, "History");
		const row = history
			.locator(`[data-testid="history-row"][data-operation="install"][data-app-id="${FIXTURE_APP}"]`)
			.filter({ hasText: "1.0.1" })
			.first();
		await expect(row.getByTestId("history-sbom")).toBeVisible();

		const [download] = await Promise.all([
			page.waitForEvent("download"),
			row.getByTestId("history-sbom").click(),
		]);
		expect(download.suggestedFilename()).toMatch(/\.cdx\.json$/);
		const bom = await json(download);
		expect(bom.bomFormat).toBe("CycloneDX");
		expect(bom.specVersion).toBe("1.5");
		const app = (bom.components ?? []).find((component: { name: string }) => component.name === FIXTURE_APP);
		expect(app?.version, "the SBOM holds the version the install left").toBe("1.0.1");
	});

	// @e2e inventory-export-sbom-metrics::the-inventory-exports-in-both-formats
	test("the Export SBOM menu downloads CycloneDX and SPDX", async ({ page }) => {
		await openSettings(page);
		const history = await openTab(page, "History");
		const menu = history.getByTestId("sbom-export");

		const [cyclonedx] = await Promise.all([
			page.waitForEvent("download"),
			menu.getByTestId("sbom-export-cyclonedx").click(),
		]);
		const cdx = await json(cyclonedx);
		expect(cdx.bomFormat).toBe("CycloneDX");
		expect(cdx.components.map((component: { name: string }) => component.name)).toContain(FIXTURE_APP);

		const [spdx] = await Promise.all([
			page.waitForEvent("download"),
			menu.getByTestId("sbom-export-spdx").click(),
		]);
		const doc = await json(spdx);
		expect(doc.spdxVersion).toBe("SPDX-2.3");
		expect(doc.packages.map((pkg: { name: string }) => pkg.name)).toContain(FIXTURE_APP);
	});

	// @e2e inventory-export-sbom-metrics::a-non-admin-gets-no-sbom
	test("a non-admin gets 403 from the SBOM endpoint", async ({ baseURL }) => {
		await occ("user:delete", "sbom-e2e-user");
		await execInInstance(
			["php", "occ", "user:add", "--password-from-env", "sbom-e2e-user"],
			{ env: { OC_PASS: "sbomUserPass123" } },
		);
		// An empty storage state: the context must not carry the admin session.
		const context = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: "sbom-e2e-user", password: "sbomUserPass123" },
		});
		try {
			const response = await context.get("/ocs/v2.php/apps/versioniq/api/sbom?format=cyclonedx", {
				headers: { "OCS-APIRequest": "true" },
			});
			expect(response.status()).toBe(403);
			expect(await response.text()).not.toContain(FIXTURE_APP);
		} finally {
			await context.dispose();
			await occ("user:delete", "sbom-e2e-user");
		}
	});
});
