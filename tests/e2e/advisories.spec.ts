import { expect, test } from "@playwright/test";
import { occ, openSettings, openTab, versionsLoaded } from "./helpers.ts";

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
