// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import {
	appConfigValue,
	FIXTURE_APP,
	fixtureAvailable,
	INSTANCE,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
	runJob,
} from "./helpers.ts";

/**
 * The bundled-library check, end to end: the fixture app ships a Composer
 * manifest naming `vendor/library` 2.4.3 (build-artifacts.sh), the fixture's
 * OSV double knows one advisory for it fixed in 2.4.5 (server.mjs), and the
 * library job turns that into a finding on the Advisories tab.
 *
 * The OSV base is https only, so the double answers on the fixture's https
 * listener; the bootstrap scripts import its certificate into Nextcloud. The
 * base is reached FROM THE INSTANCE, which is why it differs between the
 * Docker layout (the fixture container's name) and CI (the runner itself).
 * `OSV_FIXTURE_BASE` overrides both.
 *
 * @spec openspec/changes/advisories-bundled-libraries
 */
const OSV_FIXTURE_BASE =
	process.env.OSV_FIXTURE_BASE ??
	(INSTANCE.mode === "docker"
		? "https://forge-fixture:9443"
		: `https://localhost:${process.env.FORGE_FIXTURE_TLS_PORT ?? "9443"}`);

const OSV_BASE_KEY = "advisory.osv_api_base";
const ADVISORY_SETTINGS =
	"/ocs/v2.php/apps/versioniq/api/advisory/settings?format=json";
const OCS = { "OCS-APIRequest": "true" };

/** Switches the library check on or off through the advisory settings API. */
async function setLibraryCheck(page: Page, enabled: boolean): Promise<void> {
	const res = await page.request.put(ADVISORY_SETTINGS, {
		headers: { ...OCS, "Content-Type": "application/json" },
		data: { librariesEnabled: enabled ? "1" : "0" },
	});
	expect(
		res.ok(),
		`PUT /api/advisory/settings librariesEnabled=${enabled} should answer 200`,
	).toBeTruthy();
	const data = (await res.json())?.ocs?.data;
	expect(data?.librariesEnabled, "the switch must read back as saved").toBe(
		enabled,
	);
}

/** The stored advisory snapshot as `GET /api/advisories` serves it. */
async function advisories(page: Page): Promise<Record<string, unknown>> {
	const res = await page.request.get(
		"/ocs/v2.php/apps/versioniq/api/advisories?format=json",
		{ headers: OCS },
	);
	expect(res.ok(), "GET /api/advisories should answer 200").toBeTruthy();
	return (await res.json())?.ocs?.data ?? {};
}

/** The app's own advisory state, which library findings must leave alone. */
function appState(payload: Record<string, unknown>): unknown {
	const map = (payload.advisories ?? {}) as Record<
		string,
		{ state?: unknown } | undefined
	>;
	return map[FIXTURE_APP]?.state ?? null;
}

test.describe("bundled libraries", () => {
	let previousBase: string | null = null;
	let previousEnabled = false;

	test.beforeEach(async ({ page }) => {
		test.skip(
			!(await fixtureAvailable(page)),
			"forge fixture not running (see tests/e2e/fixtures/forge)",
		);
		previousBase = await appConfigValue(page, OSV_BASE_KEY);
		const settings = await page.request.get(ADVISORY_SETTINGS, {
			headers: OCS,
		});
		previousEnabled =
			(await settings.json())?.ocs?.data?.librariesEnabled === true;
		// The baseline install carries the manifest the finding comes from.
		await resetFixtureApp(page);
	});

	test.afterEach(async ({ page }) => {
		if (previousBase === null) {
			await occ("config:app:delete", "versioniq", OSV_BASE_KEY);
		} else {
			await occ(
				"config:app:set",
				"versioniq",
				OSV_BASE_KEY,
				"--value",
				previousBase,
			);
		}
		await setLibraryCheck(page, previousEnabled);
	});

	test("a bundled library with an advisory is listed with its fixed version under its app", async ({
		page,
	}) => {
		await occ(
			"config:app:set",
			"versioniq",
			OSV_BASE_KEY,
			"--value",
			OSV_FIXTURE_BASE,
		);
		await setLibraryCheck(page, true);
		const stateBefore = appState(await advisories(page));

		await runJob("LibraryAdvisoryJob");

		// The API carries the check apart from the app advisories.
		const payload = await advisories(page);
		expect(
			payload.librariesCheckedAt,
			"the library job ran, so the snapshot must carry when",
		).toEqual(expect.any(Number));
		const check = (payload.libraries as Record<string, unknown>)?.[
			FIXTURE_APP
		] as { error?: string | null } | undefined;
		expect(
			check,
			`the library snapshot has no entry for ${FIXTURE_APP}`,
		).toBeTruthy();
		expect(
			check?.error ?? null,
			`the OSV double at ${OSV_FIXTURE_BASE} was not reached — check that its certificate was imported and the https listener runs`,
		).toBeNull();

		await openSettings(page);
		await openTab(page, "Advisories");

		const block = page.locator(`#bundled-libraries-${FIXTURE_APP}`);
		await expect(block).toBeVisible();
		const finding = block.getByTestId("bundled-library-finding");
		await expect(finding).toHaveCount(1);
		await expect(finding).toContainText("vendor/library 2.4.3");
		await expect(finding).toContainText("GHSA-fx00-0000-0001");
		await expect(finding.getByTestId("bundled-library-fixed")).toHaveText(
			"Fixed in 2.4.5",
		);
		await expect(finding.getByTestId("advisory-severity")).toHaveAttribute(
			"data-severity",
			"high",
		);
		await expect(finding.getByTestId("bundled-library-malicious")).toHaveCount(
			0,
		);

		// The card on the Apps tab carries the library badge.
		await openTab(page, "Apps");
		const card = page.locator(`article[data-app-id="${FIXTURE_APP}"]`);
		await expect(card.getByTestId("library-advisory-badge")).toBeVisible();

		// The app's own advisory state is not moved by a library finding.
		expect(
			appState(await advisories(page)),
			"a library finding must leave the app's own advisory state untouched",
		).toEqual(stateBefore);
	});

	test("with the check off the job takes no new snapshot", async ({
		page,
	}) => {
		await occ(
			"config:app:set",
			"versioniq",
			OSV_BASE_KEY,
			"--value",
			OSV_FIXTURE_BASE,
		);
		await setLibraryCheck(page, false);
		const before = await advisories(page);

		await runJob("LibraryAdvisoryJob");

		const after = await advisories(page);
		expect(
			after.librariesCheckedAt ?? null,
			"a switched-off check must not take a new snapshot",
		).toEqual(before.librariesCheckedAt ?? null);
	});
});

test.describe("bundled libraries, non-admin", () => {
	test.use({ storageState: { cookies: [], origins: [] } });

	test("an unauthenticated caller cannot switch the check on", async ({
		page,
	}) => {
		const res = await page.request.put(ADVISORY_SETTINGS, {
			headers: { ...OCS, "Content-Type": "application/json" },
			data: { librariesEnabled: "1" },
		});
		expect(
			res.status(),
			"the advisory settings must refuse a caller without a session",
		).toBeGreaterThanOrEqual(400);
	});
});
