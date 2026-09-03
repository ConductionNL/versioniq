import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import {
	FIXTURE_APP,
	FIXTURE_SOURCE,
	fixtureAvailable,
	installFixture,
	occ,
	resetFixtureApp,
} from "./helpers.ts";

/**
 * Pin enforcement on Versioniq's own install path, and pin listing — driven
 * against the fixture forge (install-over-pin is refused; the pinned version
 * still reinstalls without an override).
 *
 * @spec openspec/specs/version-pinning/spec.md
 */
test.describe("pin enforcement", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
		await page.request
			.delete(
				`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/pin?format=json`,
				{
					headers: { "OCS-APIRequest": "true" },
				},
			)
			.catch(() => undefined);
	});

	async function pin(page: Page) {
		const res = await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/pin?format=json`,
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { reason: "pinned by e2e" },
			},
		);
		expect(res.ok(), "pin should succeed").toBeTruthy();
	}

	test("installing over a pin is rejected", async ({ page }) => {
		await pin(page); // pinned at 1.0.0 baseline
		const { status } = await installFixture(page, "1.0.1");
		expect(
			status,
			"install over a pin must fail (non-zero exit / 409)",
		).not.toBe(0);
		// The app stays at the pinned version.
		expect(
			(
				await occ("config:app:get", FIXTURE_APP, "installed_version")
			).trim(),
		).toBe("1.0.0");
	});

	test("reinstalling the pinned version needs no override", async ({
		page,
	}) => {
		await pin(page);
		const { status, body } = await installFixture(page, "1.0.0");
		// Same version as pinned — allowed without an override (may be a no-op).
		expect(status).toBe(0);
		expect(["none", "install", "reinstall", undefined]).toContain(
			body.updateType,
		);
	});

	test("pins are listed with their live status", async ({ page }) => {
		await pin(page);
		const res = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/pins?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		const data = (await res.json())?.ocs?.data;
		const pins = data.pins ?? data;
		const entry = pins.find((p: any) => p.appId === FIXTURE_APP);
		expect(entry, "the pin is listed").toBeTruthy();
		expect(entry.version).toBe("1.0.0");
		// Live status compares the pin against the installed version.
		expect(entry).toHaveProperty("installedVersion");
	});

	test.afterEach(async ({ page }) => {
		void FIXTURE_SOURCE;
		await page.request
			.delete(
				`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/pin?format=json`,
				{
					headers: { "OCS-APIRequest": "true" },
				},
			)
			.catch(() => undefined);
	});
});
