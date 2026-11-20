// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import { NC_ADMIN_PASS } from "../../playwright.config";
import {
	appConfigValue,
	chooseApp,
	FIXTURE_APP,
	FIXTURE_SOURCE,
	fixtureAvailable,
	fixtureControl,
	installFixture,
	openSettings,
	resetFixtureApp,
	versionsLoaded,
} from "./helpers.ts";

/**
 * Effects of forge installs and listings that span several capabilities:
 * last-known-good recording (migration-safety), artifact caching (artifact-
 * cache), forge changelog bodies (changelog-visibility), advisory correlation
 * (security-advisory-correlation), and policy validation (auto-update).
 *
 * @spec openspec/specs/migration-safety/spec.md
 * @spec openspec/specs/artifact-cache/spec.md
 * @spec openspec/specs/changelog-visibility/spec.md
 * @spec openspec/specs/security-advisory-correlation/spec.md
 * @spec openspec/specs/auto-update-policies/spec.md
 */
test.describe("install effects", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
	});

	async function versions(page: Page) {
		const res = await page.request.get(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions?source=${encodeURIComponent(FIXTURE_SOURCE)}&format=json`,
			{ headers: { "OCS-APIRequest": "true" } },
		);
		return (await res.json())?.ocs?.data;
	}

	async function cache(page: Page) {
		const res = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/cache?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		return (await res.json())?.ocs?.data;
	}

	// --- migration-safety: last-known-good ---------------------------------
	test("a successful install updates the last-known-good record", async ({
		page,
	}) => {
		await installFixture(page, "1.0.1");
		const lkg = JSON.parse(
			(await appConfigValue(page, `lkg.${FIXTURE_APP}`)) ?? "{}",
		);
		expect(lkg.version).toBe("1.0.1");
		expect(lkg.recordedAt).toBeTruthy();
	});

	test("a failed install preserves the last-known-good record", async ({
		page,
	}) => {
		await installFixture(page, "1.0.1"); // lkg = 1.0.1
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			releases: [
				{
					tag: "v2.0.0",
					asset: "fixtureapp-wrongid.tar.gz",
					sha: true,
				},
			],
		});
		await installFixture(page, "2.0.0"); // fails (appId mismatch)
		const lkg = JSON.parse(
			(await appConfigValue(page, `lkg.${FIXTURE_APP}`)) ?? "{}",
		);
		expect(lkg.version).toBe("1.0.1");
	});

	// --- artifact-cache ----------------------------------------------------
	test("a successful install populates the artifact cache", async ({
		page,
	}) => {
		await installFixture(page, "1.0.1");
		const summary = await cache(page);
		const app = (summary.apps ?? []).find(
			(a: any) => a.appId === FIXTURE_APP,
		);
		expect(app, "fixture app has a cache entry").toBeTruthy();
		expect(app.versions).toContain("1.0.1");
		expect(
			summary.totalSizeBytes ?? summary.totalSize ?? 0,
		).toBeGreaterThan(0);
	});

	test("the cache can be cleared", async ({ page }) => {
		await installFixture(page, "1.0.1");
		expect((await cache(page)).apps ?? []).not.toHaveLength(0);
		const del = await page.request.delete(
			"/ocs/v2.php/apps/versioniq/api/cache?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		expect(del.ok()).toBeTruthy();
		expect((await cache(page)).apps ?? []).toHaveLength(0);
	});

	// --- changelog-visibility ----------------------------------------------
	test("a forge release body is returned as the version changelog", async ({
		page,
	}) => {
		const data = await versions(page);
		const entry = data.availableVersions.find(
			(v: any) => v.version === "1.0.1",
		);
		expect(entry.changelog ?? "").toContain(
			"Fixture release notes for v1.0.1",
		);
	});

	// --- auto-update-policies ----------------------------------------------
	test("an invalid policy level is rejected", async ({ page }) => {
		const res = await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/policy?format=json`,
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { level: "not-a-level" },
			},
		);
		expect(res.status()).toBe(400);
		expect(await appConfigValue(page, `policy.${FIXTURE_APP}`)).toBeNull();
	});

	test.afterEach(async ({ page }) => {
		await page.request
			.delete(
				`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/policy?format=json`,
				{
					headers: { "OCS-APIRequest": "true" },
				},
			)
			.catch(() => undefined);
	});
});

/**
 * The live install checklist: while an install runs, the page polls
 * `GET /api/install-progress/{token}` and ticks each stage.
 *
 * A fixture install finishes in well under a second, faster than the page
 * polls, so the install response is held back in the browser until the
 * checklist has been read. The server-side install has completed by then, so
 * the poll reads its full record; holding the response only keeps the page in
 * its "installing" state long enough to look at it.
 *
 * The install is a dry run (the stored dry-run switch, which still sends a
 * progress token): it downloads, checks and extracts the archive but never
 * swaps the files, so it cannot hit the opcache problem `installFixture`
 * documents for installs over HTTP.
 *
 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md
 */
test.describe("live install progress", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		// resetFixtureApp also empties the artifact cache, so the archive is
		// downloaded rather than served from the cache.
		await resetFixtureApp(page);
		await page.addInitScript(() => {
			window.localStorage.setItem("versioniq_dry_run_mode", "true");
		});
	});

	// @e2e install-live-progress::an-admin-watches-an-update
	test("during a fixture install the page shows the download and extraction stages before the result", async ({
		page,
	}) => {
		let release: () => void = () => undefined;
		const held = new Promise<void>((resolve) => {
			release = resolve;
		});
		let progressToken = "";
		await page.route(`**/api/app/${FIXTURE_APP}/versions/*/install*`, async (route) => {
			progressToken = new URL(route.request().url()).searchParams.get("progressToken") ?? "";
			const response = await route.fetch();
			await held;
			await route.fulfill({ response });
		});

		await openSettings(page);
		await chooseApp(page, FIXTURE_APP);
		expect(await versionsLoaded(page), "the fixture versions load").toBe(true);
		await page.getByRole("button", { name: "Select version 1.0.1" }).click();
		await page.getByRole("button", { name: "Update", exact: true }).click();
		const password = page.locator('[role="dialog"] input[type="password"]').first();
		if (await password.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
			await password.fill(NC_ADMIN_PASS);
			await password.press("Enter");
		}

		const checklist = page.getByTestId("install-progress");
		await expect(checklist).toBeVisible({ timeout: 30_000 });
		await expect(checklist).toContainText("Download", { timeout: 30_000 });
		await expect(checklist).toContainText("Extracting", { timeout: 30_000 });
		expect(progressToken, "the page sent a progress token with the install").not.toBe("");
		await expect(page.getByText("Install result")).toBeHidden();

		// The order on the page is the order the installer reported.
		const items = await checklist.locator("li").allInnerTexts();
		const download = items.findIndex((text) => text.includes("Download"));
		const extract = items.findIndex((text) => text.includes("Extracting"));
		expect(download, items.join(" | ")).toBeGreaterThanOrEqual(0);
		expect(extract, items.join(" | ")).toBeGreaterThan(download);

		release();
		await expect(page.getByText("Install result")).toBeVisible({ timeout: 30_000 });
		await expect(checklist).toBeHidden();
	});

	test("the progress record is admin-only and unknown tokens answer 404", async ({ page }) => {
		const res = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/install-progress/no-such-token?format=json",
			{ headers: { "OCS-APIRequest": "true" } },
		);
		expect(res.status()).toBe(404);
	});
});
