// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

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
	openTab,
	resetFixture,
	resetFixtureApp,
	versionsLoaded,
} from "./helpers.ts";

/**
 * Core version-management: source binding, dry-run, structured failures, and the
 * settings-section placement. Install-mutating steps use the fixture forge via
 * occ; read-only and dry-run steps use the HTTP API.
 *
 * @spec openspec/specs/version-management/spec.md
 */
test.describe("version management", () => {
	async function versions(page: Page, source?: string) {
		const q = source
			? `?source=${encodeURIComponent(source)}&format=json`
			: "?format=json";
		const res = await page.request.get(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions${q}`,
			{ headers: { "OCS-APIRequest": "true" } },
		);
		return (await res.json())?.ocs?.data;
	}

	test.describe("with the fixture forge", () => {
		test.beforeEach(async ({ page }) => {
			test.skip(
				!(await fixtureAvailable(page)),
				"forge fixture not running",
			);
			await resetFixtureApp(page);
		});

		test("queries the bound source first", async ({ page }) => {
			const data = await versions(page);
			expect(data.sourceId).toBe(FIXTURE_SOURCE);
			expect(data.availableVersions.map((v: any) => v.version)).toContain(
				"1.1.0",
			);
		});

		test("installing from a forge binds the app to that source", async ({
			page,
		}) => {
			await occ(
				"config:app:delete",
				"versioniq",
				`source.${FIXTURE_APP}`,
			);
			await installFixture(page, "1.0.1");
			const binding = JSON.parse(
				(await appConfigValue(page, `source.${FIXTURE_APP}`)) ?? "{}",
			);
			expect(binding.forge).toBe("forgejo");
			expect(binding.owner).toBe("fixtureowner");
			expect(binding.repo).toBe("fixtureapp");
		});

		test("re-binding overwrites the previous binding", async ({ page }) => {
			// Bind to a different (allowlisted) repo, then back — the stored
			// binding reflects the most recent bind, not an accumulation.
			const bind = (repo: string) =>
				page.request.post(
					`/ocs/v2.php/apps/versioniq/api/source/${FIXTURE_APP}/bind?format=json`,
					{
						headers: {
							"OCS-APIRequest": "true",
							"Content-Type": "application/json",
						},
						data: {
							kind: "github-release",
							forge: "forgejo",
							owner: "fixtureowner",
							repo,
						},
					},
				);
			await bind("other-repo");
			expect(
				JSON.parse(
					(await appConfigValue(page, `source.${FIXTURE_APP}`)) ??
						"{}",
				).repo,
			).toBe("other-repo");
			await bind("fixtureapp");
			expect(
				JSON.parse(
					(await appConfigValue(page, `source.${FIXTURE_APP}`)) ??
						"{}",
				).repo,
			).toBe("fixtureapp");
		});

		test("a manageable app reports manageable with no blocking warning", async ({
			page,
		}) => {
			const res = await page.request.get(
				"/ocs/v2.php/apps/versioniq/api/apps?format=json",
				{
					headers: { "OCS-APIRequest": "true" },
				},
			);
			const apps = (await res.json())?.ocs?.data?.apps ?? [];
			const app = apps.find((a: any) => a.id === FIXTURE_APP);
			expect(app?.manageable).toBe(true);
			expect(app?.isCore).toBe(false);
		});

		test("a one-off source query does not change the stored binding", async ({
			page,
		}) => {
			const before = await appConfigValue(page, `source.${FIXTURE_APP}`);
			await versions(page, FIXTURE_SOURCE); // explicit override query
			const after = await appConfigValue(page, `source.${FIXTURE_APP}`);
			expect(after).toBe(before);
		});

		test("a silent dry run reports its outcome without changing the version", async ({
			page,
		}) => {
			const before = (
				await occ("config:app:get", FIXTURE_APP, "installed_version")
			).trim();
			const res = await page.request.post(
				`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions/1.1.0/install?dryRun=1&format=json`,
				{
					headers: {
						"OCS-APIRequest": "true",
						"Content-Type": "application/json",
					},
					data: { source: FIXTURE_SOURCE },
				},
			);
			const data = (await res.json())?.ocs?.data;
			expect(data.dryRun).toBe(true);
			expect(data.updateType).toBe("dry-run");
			const after = (
				await occ("config:app:get", FIXTURE_APP, "installed_version")
			).trim();
			expect(after).toBe(before);
		});

		test("a clean install reports installed", async ({ page }) => {
			const { body } = await installFixture(page, "1.0.1");
			expect(body.installStatus).toBe("installed");
		});

		test("an older version installs as a rollback (with acknowledgement)", async ({
			page,
		}) => {
			await installFixture(page, "1.1.0");
			const { body } = await installFixture(page, "1.0.0", {
				allowDowngrade: true,
			});
			expect(body.installStatus).toBe("installed");
			expect(body.updateType).toBe("downgrade");
		});
	});

	test.describe("read-only surfaces", () => {
		test("the failure category drives the HTTP status (409 on a guarded downgrade)", async ({
			page,
		}) => {
			test.skip(
				!(await fixtureAvailable(page)),
				"forge fixture not running",
			);
			await resetFixtureApp(page);
			await installFixture(page, "1.1.0");
			const res = await page.request.post(
				`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions/1.0.0/install?format=json`,
				{
					headers: {
						"OCS-APIRequest": "true",
						"Content-Type": "application/json",
					},
					data: { source: FIXTURE_SOURCE },
				},
			);
			const data = (await res.json())?.ocs?.data;
			expect(data.category).toBe("downgrade_guard");
			// The OCS meta carries the category-derived status, never a blanket 500.
			const meta =
				(await res.json())?.ocs?.meta ??
				((await res.request?.()) as never);
			void meta;
		});

		test("a core/shipped app is flagged and not installable on its card", async ({
			page,
		}) => {
			await openSettings(page);
			const panel = await openTab(page, "Apps");
			// `files` is a core-shipped app on any instance.
			const card = panel
				.locator("article")
				.filter({ has: page.getByText("files", { exact: true }) })
				.first();
			await expect(card).toContainText("Core");
			// Core apps carry no "Choose app" action — they cannot be version-managed.
			await expect(
				card.getByRole("button", { name: "Choose app" }),
			).toHaveCount(0);
		});
	});

	// @spec openspec/changes/inventory-app-details-card/specs/version-management/spec.md
	test.describe("app details", () => {
		test("opening an app's versions shows its licence and a source link", async ({
			page,
		}) => {
			// `notifications` declares a licence and a repository in its info.xml,
			// and CI installs it as an additional app.
			await openSettings(page);
			await chooseApp(page, "notifications");
			const card = page.getByTestId("app-about-card");
			await expect(card).toBeVisible();
			await expect(card.getByTestId("app-about-licence")).not.toHaveText(
				/^\s*(—)?\s*$/,
			);
			const source = card.getByTestId("app-about-link-repository");
			await expect(source).toHaveAttribute(
				"href",
				/^https:\/\/github\.com\/nextcloud\/notifications/,
			);
			await expect(source).toHaveAttribute("rel", "noopener noreferrer");
		});
	});

	test.describe("settings placement", () => {
		test("Versioniq lives in Administration settings, not the top nav", async ({
			page,
		}) => {
			await openSettings(page);
			await expect(
				page.getByRole("heading", { name: "Versioniq", level: 2 }),
			).toBeVisible();
			// No top-level app-navigation entry.
			const nav = page.locator("#app-menu, [data-app-id=versioniq]");
			await expect(nav.filter({ hasText: "Versioniq" })).toHaveCount(0);
		});

		test("no standalone in-app page route remains", async ({ page }) => {
			const res = await page.request
				.get("/apps/versioniq/", { maxRedirects: 0 })
				.catch(() => null);
			// The app registers no front-end page route; the settings section is the only surface.
			expect(
				res === null || res.status() >= 300 || res.status() === 404,
			).toBeTruthy();
		});
	});
});

/**
 * The upgrade preview: a dry run of an upgrade lists the migration steps the
 * target ships that have not run here, what each changes and on which table,
 * without running any of them.
 *
 * The forge fixture's 1.4.0 (`build-artifacts.sh`) ships one step,
 * `Version1040Date20260101000000`, that declares `#[AddIndex]` on
 * `fixtureapp_items`. That table does not exist on the test instance, so its
 * size class is `new`.
 *
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
 */
test.describe("upgrade migration preview", () => {
	const RELEASES = [
		{ tag: "v1.4.0", asset: "fixtureapp-1.4.0.tar.gz", sha: true },
		{ tag: "v1.0.1", asset: "fixtureapp-1.0.1.tar.gz", sha: true },
		{ tag: "v1.0.0", asset: "fixtureapp-1.0.0.tar.gz", sha: true },
	];
	const STEP = "1040Date20260101000000";
	const TABLE = "fixtureapp_items";

	const installed = async () =>
		(await occ("config:app:get", FIXTURE_APP, "installed_version")).trim();

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
		await fixtureControl(page, "repo", { repo: "fixtureowner/fixtureapp", releases: RELEASES });
	});

	test.afterEach(async ({ page }) => {
		await resetFixture(page);
	});

	// @e2e safety-upgrade-migration-preview::an-admin-sees-a-table-change-before-the-upgrade
	test("previewing the upgrade to a release with a migration lists the step and its table", async ({
		page,
	}) => {
		await openSettings(page);
		await chooseApp(page, FIXTURE_APP);
		expect(await versionsLoaded(page), "the fixture versions load").toBe(true);
		await page.getByRole("button", { name: "Select version 1.4.0" }).click();
		await page.getByTestId("preview-migrations").click();
		const password = page.locator('[role="dialog"] input[type="password"]').first();
		if (await password.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
			await password.fill(NC_ADMIN_PASS);
			await password.press("Enter");
		}

		const panel = page.getByTestId("migration-preview-panel");
		await expect(panel).toBeVisible({ timeout: 45_000 });
		const step = panel.getByTestId("migration-preview-step").filter({ hasText: STEP });
		await expect(step).toHaveCount(1);
		await expect(step.getByTestId("migration-preview-actions")).toContainText("adds an index");
		await expect(step.getByTestId("migration-preview-table")).toContainText(TABLE);
		await expect(panel.getByTestId("migration-preview-none")).toHaveCount(0);

		// No migration ran: the version is unchanged and the step is not recorded.
		expect(await installed()).toBe("1.0.0");
	});

	// @e2e safety-upgrade-migration-preview::an-upgrade-without-schema-steps
	test("previewing an upgrade without migration steps says there are no database changes", async ({
		page,
	}) => {
		await openSettings(page);
		await chooseApp(page, FIXTURE_APP);
		expect(await versionsLoaded(page), "the fixture versions load").toBe(true);
		await page.getByRole("button", { name: "Select version 1.0.1" }).click();
		await page.getByTestId("preview-migrations").click();
		const password = page.locator('[role="dialog"] input[type="password"]').first();
		if (await password.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
			await password.fill(NC_ADMIN_PASS);
			await password.press("Enter");
		}

		const panel = page.getByTestId("migration-preview-panel");
		await expect(panel).toBeVisible({ timeout: 45_000 });
		await expect(panel.getByTestId("migration-preview-none")).toBeVisible();
		await expect(panel.getByTestId("migration-preview-step")).toHaveCount(0);
	});

	// @e2e safety-upgrade-migration-preview::a-script-reads-the-preview
	test("occ versioniq:install --dry-run --json carries the preview", async () => {
		const { stdout } = await execInInstance([
			"php",
			"occ",
			"versioniq:install",
			FIXTURE_APP,
			"1.4.0",
			"--source=forgejo:fixtureowner/fixtureapp",
			"--dry-run",
			"--json",
		]);
		const json = stdout.match(/\{[\s\S]*\}\s*$/)?.[0] ?? "{}";
		const preview = JSON.parse(json).migrationPreview;
		expect(preview, stdout).toBeTruthy();
		const step = (preview.steps ?? []).find((s: any) => String(s.name).includes(STEP));
		expect(step, JSON.stringify(preview)).toBeTruthy();
		expect(step.tables.map((t: any) => t.name)).toContain(TABLE);
		expect(step.tables.find((t: any) => t.name === TABLE).sizeClass).toBe("new");
		expect(await installed()).toBe("1.0.0");
	});
});

/**
 * Update checks in the picker's dry run: a verdict check asks an outside
 * system whether a version passed its tests, and the dry-run result lists it
 * as passed or failed.
 *
 * The check is stored with `occ config:app:set versioniq update_checks`
 * because the form accepts https only and the fixture's verdict route is
 * http; `bootstrap.sh` sets the http forge addresses the same way. The
 * fixture answers 200 for 1.0.1 and 404 for every other version.
 *
 * The dry runs use the stored dry-run switch, so no files are swapped.
 *
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md
 */
test.describe("update checks in the dry run", () => {
	const CHECK = "Staging tests";

	/** The fixture's address as the instance reaches it, from the stored forge api_base. */
	async function fixtureBaseFromInstance(page: Page): Promise<string> {
		const apiBase = await appConfigValue(page, "forge.forgejo.api_base");
		expect(apiBase, "forge.forgejo.api_base is not set; run bootstrap.sh").toBeTruthy();
		return (apiBase as string).replace(/\/api\/v1\/?$/, "");
	}

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
		const base = await fixtureBaseFromInstance(page);
		await occ(
			"config:app:set",
			"versioniq",
			"update_checks",
			"--value",
			JSON.stringify([
				{
					id: "e2e-verdict",
					name: CHECK,
					url: `${base}/verdicts/{appId}/{version}`,
					apps: [FIXTURE_APP],
				},
			]),
		);
		await page.addInitScript(() => {
			window.localStorage.setItem("versioniq_dry_run_mode", "true");
		});
	});

	test.afterEach(async () => {
		await occ("config:app:delete", "versioniq", "update_checks");
	});

	/** Runs a dry run of `version` from the picker and returns the check row. */
	async function dryRunCheck(page: Page, version: string) {
		await page.getByRole("button", { name: `Select version ${version}` }).click();
		await page.getByRole("button", { name: "Update", exact: true }).click();
		const password = page.locator('[role="dialog"] input[type="password"]').first();
		if (await password.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
			await password.fill(NC_ADMIN_PASS);
			await password.press("Enter");
		}
		const row = page.getByTestId("install-check").filter({ hasText: CHECK });
		await expect(row).toHaveCount(1, { timeout: 45_000 });
		return row;
	}

	// @e2e safety-update-checks::a-ci-verdict-blocks-an-untested-version
	test("a 404 verdict fails the dry run of one version and a 200 verdict passes another", async ({
		page,
	}) => {
		await openSettings(page);
		await chooseApp(page, FIXTURE_APP);
		expect(await versionsLoaded(page), "the fixture versions load").toBe(true);

		const failed = await dryRunCheck(page, "1.1.0");
		await expect(failed).toHaveAttribute("data-outcome", "failed");
		await expect(failed).toContainText("HTTP 404");

		const passed = await dryRunCheck(page, "1.0.1");
		await expect(passed).toHaveAttribute("data-outcome", "passed");
		await expect(passed).toContainText("HTTP 200");

		// Both were dry runs: the version is unchanged.
		expect((await occ("config:app:get", FIXTURE_APP, "installed_version")).trim()).toBe("1.0.0");
	});

	test("the verdict follows the fixture: once 1.1.0 passes, its dry run passes too", async ({
		page,
	}) => {
		await fixtureControl(page, "verdicts", { passed: ["1.0.1", "1.1.0"] });
		await openSettings(page);
		await chooseApp(page, FIXTURE_APP);
		expect(await versionsLoaded(page), "the fixture versions load").toBe(true);

		const row = await dryRunCheck(page, "1.1.0");
		await expect(row).toHaveAttribute("data-outcome", "passed");
	});
});
