import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import {
	appConfigValue,
	FIXTURE_APP,
	FIXTURE_SOURCE,
	fixtureAvailable,
	fixtureControl,
	installFixture,
	ocsData,
	ocsRequest,
	resetFixtureApp,
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
		return await ocsData(
			page,
			"get",
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions?source=${encodeURIComponent(FIXTURE_SOURCE)}&format=json`,
		);
	}

	async function cache(page: Page) {
		return await ocsData(
			page,
			"get",
			"/ocs/v2.php/apps/versioniq/api/cache?format=json",
		);
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
		const del = await ocsRequest(
			page,
			"delete",
			"/ocs/v2.php/apps/versioniq/api/cache?format=json",
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
		const res = await ocsRequest(
			page,
			"put",
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/policy?format=json`,
			{ data: { level: "not-a-level" } },
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
