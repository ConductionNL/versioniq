// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Locator, Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import { NC_ADMIN_PASS } from "../../playwright.config";
import {
	appConfigValue,
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	fixtureControl,
	installFixture,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
	runJob,
	sql,
	sqlExec,
	versionsLoaded,
} from "./helpers.ts";

/**
 * The advisory surface, driven through the UI.
 *
 * WHAT THIS EXISTS TO PROTECT. `/api/advisories` used to correlate inline:
 * two external calls per app, ~176 sequential calls on an 88-app instance.
 * Measured on a live instance it did not answer within 120s, twice, and while
 * it held the PHP session lock the sibling `/api/pins` request never ran, so
 * pin badges silently never rendered (issue #160). Correlation now happens in
 * AdvisoryRefreshJob and the endpoint serves the stored snapshot.
 *
 * The load-bearing assertion is the one about the freshness line. A snapshot
 * that is empty and a snapshot that was never taken render identically as
 * "no advisory badges" — an absence that reads as reassurance. The line is
 * what makes those two states distinguishable to the admin, so it is tested
 * as a contract rather than as decoration.
 *
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
test.describe("security advisories", () => {
	test("the Advisories tab shows the server's own advisories and a severity per advisory", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Advisories");

		// The server row renders whether or not the feed had anything about
		// this server version (#438: it was computed and never shown).
		const server = page.getByTestId("advisories-server");
		await expect(server).toBeVisible();
		await expect(server).toContainText("Nextcloud server");

		// Every rendered severity is one of the documented levels.
		for (const chip of await page.getByTestId("advisory-severity").all()) {
			await expect(chip).toHaveAttribute(
				"data-severity",
				/^(low|medium|high|critical|unknown)$/,
			);
		}
	});

	test("the advisory surface states how old its answer is", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");
		await versionsLoaded(page);

		const freshness = page.getByTestId("advisory-freshness");
		await expect(
			freshness,
			"the advisory freshness line must always render — an empty advisory map and a sweep that never ran look identical without it",
		).toBeVisible();

		// One of the three legitimate states, and never blank. Which one depends
		// on whether cron has run on this instance, so the assertion covers the
		// set rather than pinning one and going flaky.
		await expect(freshness).toHaveText(
			/Advisories (checked|not checked yet|status unavailable)/,
		);
	});

	test("the endpoint answers promptly because it reads a snapshot rather than correlating", async ({
		page,
	}) => {
		await openSettings(page);

		// The regression this guards is a request that never returns. A budget
		// well under the old 120s non-answer is enough to catch a reversion to
		// inline correlation, without being tight enough to flake on a loaded
		// CI box.
		const started = Date.now();
		const response = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/advisories?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		const elapsedMs = Date.now() - started;

		expect(
			response.ok(),
			"GET /api/advisories should answer 200",
		).toBeTruthy();
		expect(
			elapsedMs,
			`GET /api/advisories took ${elapsedMs}ms — this endpoint reads a stored snapshot, so anything near the old inline-correlation timings means it is sweeping on the request path again`,
		).toBeLessThan(15_000);

		// `checkedAt` is part of the contract: null means "no sweep yet", a
		// number is the unix time of the last completed sweep. Its ABSENCE
		// would leave the client unable to tell those apart, so assert the key
		// exists rather than that it is truthy.
		const body = await response.json();
		const payload = body?.ocs?.data;
		expect(
			payload,
			"the OCS envelope should carry a data object",
		).toBeTruthy();
		expect(
			Object.keys(payload),
			"the response must carry checkedAt so the UI can state the age of the answer",
		).toContain("checkedAt");
	});

	test("the advisory check interval is administrator-configurable", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");

		const interval = page.getByTestId("advisory-interval");
		await expect(
			interval,
			"the interval control must be present in settings",
		).toBeVisible();

		// The bounds come from the server, so assert the control reflects them
		// rather than hardcoding 1..24 here as well — a test that pins its own
		// copy of the range stops catching a server-side change.
		const settings = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/advisory/settings?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		expect(
			settings.ok(),
			"GET /api/advisory/settings should answer 200",
		).toBeTruthy();
		const data = (await settings.json())?.ocs?.data;
		expect(
			data,
			"the OCS envelope should carry a data object",
		).toBeTruthy();
		expect(Object.keys(data)).toEqual(
			expect.arrayContaining([
				"intervalHours",
				"minIntervalHours",
				"maxIntervalHours",
				"digestEnabled",
			]),
		);

		await expect(interval).toHaveAttribute(
			"min",
			String(data.minIntervalHours),
		);
		await expect(interval).toHaveAttribute(
			"max",
			String(data.maxIntervalHours),
		);
		await expect(interval).toHaveValue(String(data.intervalHours));

		await expect(page.getByTestId("advisory-digest-enabled")).toBeVisible();
	});

	test("an out-of-range interval is refused rather than silently clamped", async ({
		page,
	}) => {
		await openSettings(page);

		const settings = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/advisory/settings?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		const before = (await settings.json())?.ocs?.data;

		// A UI told "200 OK" while the server stored something else has been
		// lied to. The store still clamps for values arriving via occ; the API
		// must say no.
		const rejected = await page.request.put(
			"/ocs/v2.php/apps/versioniq/api/advisory/settings?format=json",
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { intervalHours: String(before.maxIntervalHours + 24) },
			},
		);
		expect(
			rejected.status(),
			"an interval above the supported maximum must be refused, not accepted-and-clamped",
		).toBe(400);

		// And the stored value must be untouched by the refusal.
		const after = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/advisory/settings?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		expect((await after.json())?.ocs?.data?.intervalHours).toBe(
			before.intervalHours,
		);
	});

	test("the refresh job is registered, so a snapshot will actually be produced", async () => {
		// Without this the endpoint is honest but permanently empty: it would
		// report "not checked yet" forever and nothing would ever say why.
		// Reading the job list proves the sweep is wired to cron, which no
		// amount of UI assertion can.
		const jobs = await occ("background-job:list", "--output=json");
		expect(
			jobs,
			"AdvisoryRefreshJob must be registered as a background job — the advisory snapshot has no other writer",
		).toContain("AdvisoryRefreshJob");
	});
});

/**
 * Advisory triage (advisories-triage 4.3 and 4.4): dismissing, reopening and
 * assigning advisories, against advisories the fixture forge serves.
 *
 * The server advisory comes from the fixture forge too: `advisory.feed_base`
 * points the Nextcloud advisory feed at a fixture repository, so the check
 * reads a known advisory instead of the live GitHub feed.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md
 */
test.describe("advisory triage", () => {
	const SERVER_ADVISORY = "GHSA-e2e0-tria-0001";
	const APP_ADVISORY = "GHSA-e2e0-tria-0002";
	const SECOND_ADMIN = "triage-e2e-admin";
	const SECOND_PASS = "triageAdminPass123";

	/** A GHSA-shaped record for one package and version range. */
	function advisory(id: string, name: string, range: string, severity: string) {
		return {
			ghsa_id: id,
			severity,
			summary: `Fixture advisory ${id}`,
			published_at: "2026-01-01T00:00:00Z",
			vulnerabilities: [
				{
					package: { ecosystem: "other", name },
					vulnerable_version_range: range,
					patched_versions: "",
					first_patched_version: null,
				},
			],
		};
	}

	/**
	 * Confirms the admin password when Nextcloud asks for it: dismiss, reopen
	 * and assign are password-confirmed, and a session older than the
	 * confirmation window gets the dialog.
	 */
	async function confirmPasswordIfAsked(page: Page, password: string) {
		const field = page.locator(
			'.dialog input[type="password"], [role="dialog"] input[type="password"]',
		);
		try {
			await field.first().waitFor({ state: "visible", timeout: 3_000 });
		} catch {
			return;
		}
		await field.first().fill(password);
		await field.first().press("Enter");
		await expect(field.first()).toBeHidden();
	}

	/** The advisory item for an id inside a region of the Advisories tab. */
	function item(region: Locator, id: string): Locator {
		return region.getByTestId("advisory-item").filter({ hasText: id });
	}

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
		await occ("config:app:delete", "versioniq", "advisory.triage");
		await occ("config:app:delete", "versioniq", "advisory.feed_source");

		// The app reaches the fixture at the forge web base bootstrap set.
		const forgeBase = await appConfigValue(page, "forge.forgejo.web_base");
		test.skip(!forgeBase, "forge.forgejo.web_base is not set");
		await occ(
			"config:app:set",
			"versioniq",
			"advisory.feed_base",
			`--value=${forgeBase}/repos/nextcloud/security-advisories/security-advisories`,
		);
		await fixtureControl(page, "repo", {
			repo: "nextcloud/security-advisories",
			releases: [],
			advisories: [advisory(SERVER_ADVISORY, "server", ">= 1.0.0", "moderate")],
		});
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			advisories: [advisory(APP_ADVISORY, FIXTURE_APP, "< 1.1.0", "high")],
		});
		await runJob("AdvisoryRefreshJob");
	});

	test.afterEach(async ({ page }) => {
		await occ("config:app:delete", "versioniq", "advisory.feed_base");
		await occ("config:app:delete", "versioniq", "advisory.triage");
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			advisories: [],
		}).catch(() => undefined);
		await fixtureControl(page, "repo", {
			repo: "nextcloud/security-advisories",
			releases: [],
			advisories: [],
		}).catch(() => undefined);
	});

	// @e2e advisories-triage::an-admin-dismisses-and-reopens-a-server-advisory
	test("an admin dismisses a server advisory as not used, sees it under Dismissed and reopens it", async ({
		page,
	}) => {
		await openSettings(page);
		const panel = await openTab(page, "Advisories");
		const server = panel.getByTestId("advisories-server");

		const details = server.locator("details").filter({ has: page.getByTestId("advisory-item") }).first();
		if (!(await details.evaluate((el) => (el as HTMLDetailsElement).open))) {
			await details.locator("summary").first().click();
		}
		await item(server, SERVER_ADVISORY).getByTestId("advisory-dismiss").click();

		const dialog = page.getByTestId("dismiss-advisory-dialog");
		await expect(dialog).toBeVisible();
		await dialog.getByTestId("dismiss-reason-not_used").click();
		await dialog.getByTestId("dismiss-comment").locator("textarea").fill("We do not use this component.");
		await page.getByRole("button", { name: "Dismiss advisory" }).last().click();
		await confirmPasswordIfAsked(page, NC_ADMIN_PASS);
		await expect(dialog).toBeHidden();

		const dismissed = server.getByTestId("advisories-dismissed");
		await expect(dismissed).toBeVisible();
		await dismissed.locator("summary").click();
		const entry = dismissed.getByTestId("dismissed-item").filter({ hasText: SERVER_ADVISORY });
		await expect(entry).toContainText("We do not use this component.");
		await expect(entry).toContainText("Dismissed by admin");

		await entry.getByTestId("advisory-reopen").click();
		await confirmPasswordIfAsked(page, NC_ADMIN_PASS);
		await expect(server.getByTestId("dismissed-item").filter({ hasText: SERVER_ADVISORY })).toHaveCount(0);
		await expect(item(server, SERVER_ADVISORY).getByTestId("advisory-dismiss")).toBeVisible();
	});

	// @e2e advisories-triage::a-dismissal-lapses-on-a-version-change-and-an-assignee-sees-it
	test("a dismissal lapses when the version changes, and an assigned admin sees the advisory as theirs", async ({
		page,
		browser,
		baseURL,
	}) => {
		await openSettings(page);
		let panel = await openTab(page, "Advisories");
		let row = panel.getByTestId("advisories-app-row").filter({ hasText: FIXTURE_APP });
		await item(row, APP_ADVISORY).getByTestId("advisory-dismiss").click();
		const dialog = page.getByTestId("dismiss-advisory-dialog");
		await dialog.getByTestId("dismiss-reason-tolerable_risk").click();
		await page.getByRole("button", { name: "Dismiss advisory" }).last().click();
		await confirmPasswordIfAsked(page, NC_ADMIN_PASS);
		await expect(dialog).toBeHidden();
		await expect(row.getByTestId("dismissed-item").filter({ hasText: APP_ADVISORY })).toHaveCount(1);

		// 1.0.1 is still below 1.1.0, so still affected: the dismissal was for
		// 1.0.0 and lapses with the version change.
		const installed = await installFixture(page, "1.0.1");
		expect(installed.status, JSON.stringify(installed.body)).toBe(0);
		await runJob("AdvisoryRefreshJob");

		await openSettings(page);
		panel = await openTab(page, "Advisories");
		row = panel.getByTestId("advisories-app-row").filter({ hasText: FIXTURE_APP });
		await expect(row.getByTestId("dismissed-item").filter({ hasText: APP_ADVISORY })).toHaveCount(0);
		await expect(item(row, APP_ADVISORY).getByTestId("advisory-dismiss")).toBeVisible();

		// A second admin.
		await occ("user:delete", SECOND_ADMIN);
		await execInInstance(
			["php", "occ", "user:add", "--password-from-env", SECOND_ADMIN],
			{ env: { OC_PASS: SECOND_PASS } },
		);
		await occ("group:adduser", "admin", SECOND_ADMIN);
		await sqlExec(`DELETE FROM oc_notifications WHERE app='versioniq' AND user='${SECOND_ADMIN}'`);

		await item(row, APP_ADVISORY).getByTestId("advisory-assign").click();
		const assign = page.getByTestId("assign-advisory-dialog");
		await expect(assign).toBeVisible();
		await assign.getByTestId("assign-id").locator("input").fill(SECOND_ADMIN);
		await page.getByRole("button", { name: "Assign", exact: true }).last().click();
		await confirmPasswordIfAsked(page, NC_ADMIN_PASS);
		await expect(assign).toBeHidden();
		await expect(item(row, APP_ADVISORY).getByTestId("advisory-owner")).toContainText(SECOND_ADMIN);

		await expect
			.poll(async () =>
				sql(`SELECT subject FROM oc_notifications WHERE app='versioniq' AND user='${SECOND_ADMIN}'`),
			)
			.toContain("advisory_assigned");

		// The assignee, in a context with an empty storage state.
		const context = await browser.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			ignoreHTTPSErrors: true,
		});
		try {
			const theirs = await context.newPage();
			await theirs.goto("/login");
			await theirs.locator("#user").fill(SECOND_ADMIN);
			await theirs.locator("#password").fill(SECOND_PASS);
			await theirs.locator("button[type=submit]").click();
			await theirs.waitForURL((url) => !url.pathname.includes("/login"));

			await openSettings(theirs);
			const mine = await openTab(theirs, "Advisories");
			await mine.getByTestId("advisories-only-mine").locator("input").check({ force: true });
			const theirRow = mine.getByTestId("advisories-app-row").filter({ hasText: FIXTURE_APP });
			await expect(theirRow).toHaveCount(1);
			await expect(item(theirRow, APP_ADVISORY)).toBeVisible();
		} finally {
			await context.close();
			await occ("user:delete", SECOND_ADMIN);
		}
	});
});
