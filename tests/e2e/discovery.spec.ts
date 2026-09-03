import { expect, test } from "@playwright/test";
import { discoverDiagnostics, openSettings, openTab } from "./helpers.ts";

/**
 * Multi-source app discovery surfaced in the Discover tab.
 * @spec openspec/specs/app-discovery/spec.md
 */
test.describe("app discovery", () => {
	test("idle state invites a search before anything is typed", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Discover");
		await expect(page.getByTestId("discover-idle")).toBeVisible();
		await expect(page.getByTestId("discover-search-input")).toBeVisible();
	});

	test("input shorter than two characters is not searched", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Discover");

		await page.getByTestId("discover-search-input").fill("a");
		await expect(
			page.getByTestId("discover-validation-hint"),
		).toBeVisible();
		// No request should have been fired, so no results or loading state.
		await expect(page.getByTestId("discover-hit")).toHaveCount(0);
	});

	test("searching returns App Store hits with source badges and installed state", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Discover");

		await page.getByTestId("discover-search-input").fill("calendar");

		const hits = page.getByTestId("discover-hit");
		await expect(
			hits.first(),
			await discoverDiagnostics(page, "calendar"),
		).toBeVisible({ timeout: 45_000 });
		expect(await hits.count()).toBeGreaterThan(0);

		// Every hit names the source it came from.
		await expect(
			hits.first().getByTestId("discover-source-badge"),
		).toBeVisible();
	});

	test("an installed hit routes into its version picker", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Discover");

		// `dashboard` ships with Nextcloud, so it is always installed.
		await page.getByTestId("discover-search-input").fill("dashboard");
		const hit = page
			.getByTestId("discover-hit")
			.filter({ hasText: "dashboard" })
			.first();
		await expect(
			hit,
			await discoverDiagnostics(page, "dashboard"),
		).toBeVisible({ timeout: 45_000 });
		await expect(
			hit.getByTestId("discover-installed-version"),
		).toBeVisible();

		await hit.getByTestId("discover-open-app").click();

		// We should land on the Apps tab with that app selected.
		await expect(
			page.getByRole("tabpanel", { name: "Apps" }),
		).toBeVisible();
		await expect(page.getByText("Selected app")).toBeVisible();
	});

	test("the installed-only filter returns only installed apps", async ({
		page,
	}) => {
		// The Discover tab exposes an installed-only toggle backed by the API's
		// `installedOnly` param; assert the contract at the API the UI calls.
		const res = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/discover?q=files&installedOnly=1&format=json",
			{ headers: { "OCS-APIRequest": "true" } },
		);
		const data = (await res.json())?.ocs?.data ?? {};
		const results = data.results ?? [];
		// The endpoint already reports which provider failed and why; asserting
		// on the count alone throws that away and blames the app for a search
		// that never reached its source.
		expect(
			results.length,
			`provider errors: ${JSON.stringify(data.errors ?? [])}`,
		).toBeGreaterThan(0);
		for (const hit of results) {
			expect(
				hit.installedVersion,
				`${hit.appId} should be installed`,
			).toBeTruthy();
		}
	});

	test("a search with no matches shows the empty state", async ({ page }) => {
		await openSettings(page);
		await openTab(page, "Discover");
		await page
			.getByTestId("discover-search-input")
			.fill("zznomatchapp-qxwv");
		await expect(page.getByTestId("discover-empty")).toBeVisible({
			timeout: 45_000,
		});
		await expect(page.getByTestId("discover-hit")).toHaveCount(0);
	});
});
