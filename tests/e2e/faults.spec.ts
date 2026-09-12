import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import {
	execInInstance,
	FIXTURE_APP,
	FIXTURE_SOURCE,
	fixtureAvailable,
	fixtureControl,
	installFixture,
	occ,
	ocsData,
	resetFixtureApp,
} from "./helpers.ts";

/**
 * Fault, migration-diff and cache-integrity paths — driven against the fixture
 * forge with crafted archives (a migration-bearing version, a finalize-failing
 * version) and cache/allowlist manipulation.
 *
 * @spec openspec/specs/migration-safety/spec.md
 * @spec openspec/specs/artifact-cache/spec.md
 * @spec openspec/specs/version-management/spec.md
 */
test.describe("faults, diffs and cache integrity", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
	});

	async function dryRunDowngrade(page: Page, version: string) {
		return await ocsData(
			page,
			"post",
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions/${version}/install?dryRun=1&allowDowngrade=1&format=json`,
			{ data: { source: FIXTURE_SOURCE } },
		);
	}

	// --- migration-safety: diff -------------------------------------------
	test("a downgrade names the migration steps the target lacks", async ({
		page,
	}) => {
		// Install 1.2.0 (ships Version1020...), then a dry-run downgrade to 1.0.0
		// must report the orphaned migration the older release does not carry.
		await installFixture(page, "1.2.0");
		const data = await dryRunDowngrade(page, "1.0.0");
		expect(JSON.stringify(data.orphanedMigrations ?? [])).toContain(
			"Version1020",
		);
	});

	test("a downgrade between releases with the same migrations reports no drift", async ({
		page,
	}) => {
		// 1.0.1 -> 1.0.0: neither ships a migration, so the orphaned list is empty.
		await installFixture(page, "1.0.1");
		const data = await dryRunDowngrade(page, "1.0.0");
		expect(data.orphanedMigrations ?? []).toHaveLength(0);
	});

	// --- version-management: finalize failure reverts ----------------------
	test("a finalize-phase failure reverts and reports installed-but-broken", async ({
		page,
	}) => {
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			releases: [
				{ tag: "v1.3.0", asset: "fixtureapp-1.3.0.tar.gz", sha: true },
			],
		});
		const { body } = await installFixture(page, "1.3.0");
		expect(body.installStatus).not.toBe("installed");
		// The migration threw during finalize; the installer must not surface a
		// raw fatal, and the app must be back at (or restored toward) 1.0.0.
		expect(JSON.stringify(body).toLowerCase()).toMatch(
			/finaliz|revert|broken|migrat/,
		);
		expect(
			(
				await occ("config:app:get", FIXTURE_APP, "installed_version")
			).trim(),
		).toBe("1.0.0");
	});

	// --- artifact-cache integrity -----------------------------------------
	test("the cache retains only the configured number of artifacts", async ({
		page,
	}) => {
		await occ(
			"config:app:set",
			"versioniq",
			"artifact_cache_keep",
			"--value",
			"2",
		);
		await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.0.0",
			`--source=${FIXTURE_SOURCE}`,
			"--allow-downgrade",
			"--json",
		);
		await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.0.1",
			`--source=${FIXTURE_SOURCE}`,
			"--json",
		);
		await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.1.0",
			`--source=${FIXTURE_SOURCE}`,
			"--json",
		);

		const summary = await ocsData(
			page,
			"get",
			"/ocs/v2.php/apps/versioniq/api/cache?format=json",
		);
		const app = (summary?.apps ?? []).find(
			(a: any) => a.appId === FIXTURE_APP,
		);
		expect(
			app.versions.length,
			"kept at most 2 (oldest evicted)",
		).toBeLessThanOrEqual(2);
		expect(app.versions).toContain("1.1.0"); // newest retained
		await occ("config:app:delete", "versioniq", "artifact_cache_keep");
	});

	test("a cached artifact for a now-untrusted source is not served", async ({
		page,
	}) => {
		// Install & cache 1.0.1, then move to 1.1.0 so 1.0.1 is a rollback target,
		// then remove the fixture repo from the allowlist and 404 the live asset.
		await installFixture(page, "1.0.1");
		await installFixture(page, "1.1.0");
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz",
			status: 404,
		});
		await occ(
			"config:app:set",
			"versioniq",
			"trusted_sources",
			"--value",
			'["github:ConductionNL/*"]',
		);

		const { status, body } = await installFixture(page, "1.0.1", {
			allowDowngrade: true,
		});
		expect(
			status,
			"untrusted install refused even with a cache hit",
		).not.toBe(0);
		expect(body.servedFromCache ?? false).toBeFalsy();

		// restore the allowlist for later tests
		await occ(
			"config:app:set",
			"versioniq",
			"trusted_sources",
			"--value",
			'["github:ConductionNL/*","codeberg:Conduction/*","codeberg:fixtureowner/*","github:fixtureowner/*"]',
		);
	});

	test("a tampered cached artifact is discarded, not installed", async ({
		page,
	}) => {
		// Cache 1.0.1, move to 1.1.0, corrupt the cached file, and 404 the live
		// asset. The cache re-verification must reject the tampered bytes.
		await installFixture(page, "1.0.1");
		await installFixture(page, "1.1.0");
		const dir =
			(await occ("config:system:get", "datadirectory")).trim() ||
			"/var/www/html/data";
		// Overwrite every cached 1.0.1 archive with garbage.
		await occ("config:app:get", FIXTURE_APP, "installed_version"); // no-op keeps occ imported
		await execInInstance(
			[
				"bash",
				"-c",
				`find ${dir} -path '*artifact-cache*1.0.1*' -name '*.tar.gz' -exec sh -c 'echo tampered > "$1"' _ {} \\;`,
			],
			{ asRoot: true },
		);
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz",
			status: 404,
		});

		const { status, body } = await installFixture(page, "1.0.1", {
			allowDowngrade: true,
		});
		expect(status, "tampered cache must not install").not.toBe(0);
		expect(body.installStatus).not.toBe("installed");
	});

	test("an unreachable App Store surfaces an error, not silent zero versions", async ({
		page,
	}) => {
		// Point the App Store base at a dead endpoint and clear its payload cache,
		// then query a store-bound app: the listing must report an error.
		await occ(
			"config:app:set",
			"versioniq",
			"appstore.api_base",
			"--value",
			"http://forge-fixture:9099/no-such-appstore",
		);
		await occ(
			"config:app:delete",
			"versioniq",
			"appstore.payload.activity",
		);
		await occ(
			"config:app:delete",
			"versioniq",
			"appstore.payload_ts.activity",
		);
		const data = await ocsData(
			page,
			"get",
			"/ocs/v2.php/apps/versioniq/api/app/activity/versions?format=json",
		);
		expect(data.error ?? "", "an error is surfaced").not.toBe("");
		expect(data.availableVersions ?? []).toHaveLength(0);
		await occ("config:app:delete", "versioniq", "appstore.api_base");
	});
});
