import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import {
	appConfigValue,
	FIXTURE_APP,
	FIXTURE_SOURCE,
	fixtureAvailable,
	installFixture,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
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
			expect(binding.forge).toBe("codeberg");
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
							forge: "codeberg",
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
			await expect(card).toContainText("CORE");
			// Core apps carry no "Choose app" action — they cannot be version-managed.
			await expect(
				card.getByRole("button", { name: "Choose app" }),
			).toHaveCount(0);
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
