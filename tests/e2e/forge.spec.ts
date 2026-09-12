import { expect, test } from "@playwright/test";
import {
	appConfigValue,
	FIXTURE_APP,
	FIXTURE_SOURCE,
	fixtureAvailable,
	fixtureControl,
	installFixture,
	ocsData,
	openSettings,
	resetFixtureApp,
} from "./helpers.ts";

/**
 * Forge installs driven against a fixture forge (tests/e2e/fixtures/forge).
 *
 * These cover the scenarios that are impossible to assert against a real forge:
 * a version-specific install, TOFU digest recording and enforcement, integrity
 * failures, rate-limiting, a missing repo, offline artifact-cache fallback, and
 * the advisory feed. The fixture must be bootstrapped first (see docs/e2e.md);
 * when it is not reachable the whole file skips rather than failing.
 *
 * @spec openspec/specs/external-sources/spec.md
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/specs/migration-safety/spec.md
 * @spec openspec/specs/artifact-cache/spec.md
 */
test.describe("forge installs (fixture-backed)", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
	});

	test("installs a specific newer version from the forge", async ({
		page,
	}) => {
		const { body } = await installFixture(page, "1.0.1");
		expect(body.installStatus).toBe("installed");
		expect(body.installedVersion).toBe("1.0.1");
		expect(body.updateType).toBe("upgrade");
	});

	test("records the observed SHA-256 on first successful install (TOFU)", async ({
		page,
	}) => {
		await installFixture(page, "1.0.1");
		const binding = JSON.parse(
			(await appConfigValue(page, `source.${FIXTURE_APP}`)) ?? "{}",
		);
		expect(
			binding.sha256?.["1.0.1"],
			"digest recorded for the installed version",
		).toMatch(/^[a-f0-9]{64}$/);
	});

	test("a rewritten release fails closed against the recorded digest", async ({
		page,
	}) => {
		// Record the digest of the genuine 1.0.1, then move off it so a later
		// 1.0.1 install genuinely re-downloads (a same-version reinstall is a
		// no-op that would never re-verify).
		await installFixture(page, "1.0.1");
		await installFixture(page, "1.0.0", { allowDowngrade: true });
		// The forge now serves different bytes for the same 1.0.1 asset.
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz",
			serveInstead: "fixtureapp-1.0.1-tampered.tar.gz",
		});
		// Swap the sibling too, so it matches the tampered bytes and it is the
		// recorded TOFU digest — not the sibling — that catches the rewrite.
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz.sha256",
			serveInstead: "fixtureapp-1.0.1-tampered.tar.gz.sha256",
		});
		const { body } = await installFixture(page, "1.0.1");
		// Must be refused, and the app must remain at the version it was.
		expect(body.installStatus).not.toBe("installed");
		expect(JSON.stringify(body).toLowerCase()).toMatch(
			/sha|checksum|digest|integrity/,
		);
	});

	test("acceptNewSha installs a rewritten release the default refuses", async ({
		page,
	}) => {
		// Record the genuine digest, move off it, then rewrite the 1.0.1 release
		// (bytes + sibling) so a plain reinstall must fail closed on the recorded
		// digest but an acknowledged one is accepted.
		await installFixture(page, "1.0.1");
		await installFixture(page, "1.0.0", { allowDowngrade: true });
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz",
			serveInstead: "fixtureapp-1.0.1-tampered.tar.gz",
		});
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz.sha256",
			serveInstead: "fixtureapp-1.0.1-tampered.tar.gz.sha256",
		});

		// Default: refused against the recorded digest.
		const refused = await installFixture(page, "1.0.1");
		expect(refused.body.installStatus).not.toBe("installed");
		expect(JSON.stringify(refused.body).toLowerCase()).toMatch(
			/sha|checksum|digest|integrity/,
		);

		// Acknowledged: the same rewritten release installs.
		const accepted = await installFixture(page, "1.0.1", {
			acceptNewSha: true,
		});
		expect(accepted.body.installStatus).toBe("installed");
		expect(accepted.body.installedVersion).toBe("1.0.1");
	});

	test("a downgrade is refused without acknowledgement, then proceeds with it", async ({
		page,
	}) => {
		await installFixture(page, "1.1.0"); // move above baseline
		const refused = await installFixture(page, "1.0.0");
		expect(refused.body.category).toBe("downgrade_guard");
		expect(refused.body.installStatus).not.toBe("installed");

		const ok = await installFixture(page, "1.0.0", {
			allowDowngrade: true,
		});
		expect(ok.body.installStatus).toBe("installed");
		expect(ok.body.installedVersion).toBe("1.0.0");
	});

	test("a missing SHA-256 sibling is a warning, not a failure", async ({
		page,
	}) => {
		// Serve a release whose asset has no .sha256 sibling.
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			releases: [
				{ tag: "v1.0.1", asset: "fixtureapp-1.0.1.tar.gz", sha: false },
			],
		});
		const { body } = await installFixture(page, "1.0.1");
		expect(body.installStatus).toBe("installed");
	});

	test("a rate-limited forge surfaces an error in the version list", async ({
		page,
	}) => {
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			status: 429,
		});
		const data = await ocsData(
			page,
			"get",
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions?source=${encodeURIComponent(FIXTURE_SOURCE)}&format=json`,
		);
		expect(
			data.error ?? "",
			"an error should be reported, not silent zero versions",
		).not.toBe("");
		expect(data.availableVersions ?? []).toHaveLength(0);
	});

	test("offline artifact cache serves a rollback when the release is gone", async ({
		page,
	}) => {
		// Install 1.0.1 so its artifact is cached, then move up so 1.0.1 is a
		// rollback target, then make the forge 404 the 1.0.1 asset.
		await installFixture(page, "1.0.1");
		await installFixture(page, "1.1.0");
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz",
			status: 404,
		});
		const { body } = await installFixture(page, "1.0.1", {
			allowDowngrade: true,
		});
		expect(
			body.installStatus,
			"cache should serve the vanished release",
		).toBe("installed");
		expect(body.servedFromCache).toBe(true);
	});

	test("offline cache badge shows in the version picker after an install", async ({
		page,
	}) => {
		await installFixture(page, "1.0.1");
		await openSettings(page);
		const card = page
			.locator("article")
			.filter({ has: page.getByText(FIXTURE_APP, { exact: true }) })
			.first();
		await card.getByRole("button", { name: "Choose app" }).click();
		await expect(page.getByText("Selected app")).toBeVisible();
		// The picker (safe mode off to see all versions) marks cached versions.
		await page
			.getByRole("checkbox", { name: /Safe mode/ })
			.uncheck()
			.catch(() => undefined);
		// 45s, not 60s: a wait equal to the test bound leaves no room for the
		// steps around it, so it expires as an unexplained test timeout rather
		// than as this assertion failing.
		await expect(
			page.getByTestId("cached-offline-badge").first(),
		).toBeVisible({ timeout: 45_000 });
	});
});
