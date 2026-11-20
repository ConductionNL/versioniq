// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import { NC_ADMIN_PASS } from "../../playwright.config";
import {
	appConfigValue,
	chooseApp,
	execInInstance,
	FIXTURE_APP,
	FIXTURE_SOURCE,
	fixtureAvailable,
	fixtureControl,
	installFixture,
	occ,
	openSettings,
	resetFixtureApp,
	versionsLoaded,
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
		const res = await page.request.get(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions?source=${encodeURIComponent(FIXTURE_SOURCE)}&format=json`,
			{ headers: { "OCS-APIRequest": "true" } },
		);
		const data = (await res.json())?.ocs?.data;
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

/**
 * Installing past a version's declared maximum (install-force-incompatible 4.4).
 *
 * Release 1.0.2 of the fixture app declares Nextcloud 28-30, below every
 * server the suite runs on. A plain install is refused; "Install anyway"
 * installs it through `app_install_overwrite`, and the card says so.
 *
 * A forge release publishes no Nextcloud range, so the picker only knows a
 * version is too old for this server once its artifact is cached: the test
 * seeds the cache with one forced install from occ and moves back to 1.0.0
 * before driving the page.
 *
 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
 */
test.describe("installing past the declared maximum", () => {
	const RELEASES = [
		{ tag: "v1.0.2", asset: "fixtureapp-1.0.2-maxold.tar.gz", sha: true },
		{ tag: "v1.0.1", asset: "fixtureapp-1.0.1.tar.gz", sha: true },
		{ tag: "v1.0.0", asset: "fixtureapp-1.0.0.tar.gz", sha: true },
	];

	/** Takes fixtureapp off app_install_overwrite and drops Versioniq's record of it. */
	async function clearOverride() {
		await execInInstance([
			"php",
			"-r",
			"require 'lib/base.php'; $c = \\OC::$server->get(\\OCP\\IConfig::class); $list = $c->getSystemValue('app_install_overwrite', []); $c->setSystemValue('app_install_overwrite', array_values(array_diff(is_array($list) ? $list : [], ['" + FIXTURE_APP + "'])));",
		]);
		await occ("config:app:delete", "versioniq", `force_incompatible.${FIXTURE_APP}`);
	}

	const installed = async () =>
		(await occ("config:app:get", FIXTURE_APP, "installed_version")).trim();

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await clearOverride();
		await resetFixtureApp(page);
		await fixtureControl(page, "repo", { repo: "fixtureowner/fixtureapp", releases: RELEASES });
	});

	test.afterEach(async ({ page }) => {
		await clearOverride();
		await resetFixtureApp(page);
	});

	// @e2e install-force-incompatible::install-anyway-past-the-maximum
	test("a version past its declared maximum is refused, installs with Install anyway, and the card says so", async ({
		page,
	}) => {
		// The refusal: a plain install of 1.0.2 does not change the version.
		const refused = await installFixture(page, "1.0.2");
		expect(refused.status, JSON.stringify(refused.body)).toBe(7);
		expect(await installed()).toBe("1.0.0");

		// Seed the artifact cache with one forced install, then move back.
		const seeded = await installFixture(page, "1.0.2", { forceIncompatible: true });
		expect(seeded.status, JSON.stringify(seeded.body)).toBe(0);
		await installFixture(page, "1.0.0", { allowDowngrade: true });
		await clearOverride();
		expect(await installed()).toBe("1.0.0");

		await openSettings(page);
		await chooseApp(page, FIXTURE_APP);
		expect(await versionsLoaded(page), "the fixture versions load").toBe(true);

		await page.getByTestId("install-anyway-button").first().click();
		const dialog = page.getByRole("dialog").filter({ has: page.getByTestId("force-warning") });
		await expect(dialog.getByTestId("force-warning")).toContainText("app_install_overwrite");
		await dialog.getByRole("button", { name: "Install anyway", exact: true }).click();
		const password = page.locator('[role="dialog"] input[type="password"]').first();
		if (await password.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
			await password.fill(NC_ADMIN_PASS);
			await password.press("Enter");
		}

		await expect.poll(installed, { timeout: 60_000 }).toBe("1.0.2");
		expect(
			await occ("config:system:get", "app_install_overwrite"),
			"fixtureapp is on the compatibility override",
		).toContain(FIXTURE_APP);

		await openSettings(page);
		await page
			.getByRole("tablist", { name: "Versioniq sections" })
			.getByRole("tab", { name: "Apps", exact: true })
			.click();
		const card = page
			.locator("article")
			.filter({ has: page.getByText(FIXTURE_APP, { exact: true }) })
			.first();
		await expect(card.getByTestId("app-override-notice")).toContainText("Forced to run on Nextcloud");
	});
});

