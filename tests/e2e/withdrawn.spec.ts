// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
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
	resetFixture,
	resetFixtureApp,
	runJob,
} from "./helpers.ts";

/**
 * Withdrawn versions (safety-withdrawn-and-revoked 3.2), driven against the
 * fixture forge.
 *
 * The fixture app is installed at 1.0.0 and the forge lists 0.9.0 to 1.2.0. A
 * first availability sweep records that listing as the baseline; the spec then
 * deletes a release from the forge and sweeps again, which is how a publisher
 * withdrawing a release looks to Versioniq (design D3). Deleting the installed
 * 1.0.0 must put "Version withdrawn by the publisher" on the card; deleting
 * 1.0.1 must make an install of it refuse with category `withdrawn`, even once
 * the forge lists it again without a sweep in between — the stand-in for the
 * stale cached catalogue of the spec's scenario.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md
 */

/** The forge's default release set (server.mjs DEFAULT_RELEASES). */
const DEFAULT_RELEASES = [
	{ tag: "v1.2.0", asset: "fixtureapp-1.2.0.tar.gz", sha: true },
	{ tag: "v1.1.0", asset: "fixtureapp-1.1.0.tar.gz", sha: true },
	{ tag: "v1.0.1", asset: "fixtureapp-1.0.1.tar.gz", sha: true },
	{ tag: "v1.0.0", asset: "fixtureapp-1.0.0.tar.gz", sha: true },
	{ tag: "v0.9.0", asset: "fixtureapp-0.9.0.tar.gz", sha: true },
];

/** Makes the forge list every default release except one tag. */
async function withdrawRelease(page: Page, tag: string): Promise<void> {
	await fixtureControl(page, "repo", {
		repo: "fixtureowner/fixtureapp",
		releases: DEFAULT_RELEASES.filter((r) => r.tag !== tag),
	});
}

/** The fixture app's row in the stored availability snapshot. */
async function storedRow(): Promise<Record<string, unknown>> {
	const { stdout } = await execInInstance(["php", "occ", "versioniq:updates", "--json"]);
	const m = stdout.match(/\{[\s\S]*\}\s*$/);
	return m ? (JSON.parse(m[0]).updates?.[FIXTURE_APP] ?? {}) : {};
}

/** Forgets what the sweep recorded about the fixture app's listings. */
async function forgetListings(): Promise<void> {
	await occ("config:app:delete", "versioniq", `withdrawn.${FIXTURE_APP}`);
	await occ("config:app:delete", "versioniq", `availability.listed.${FIXTURE_APP}`);
}

test.describe("withdrawn versions", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		test.setTimeout(180_000);
		await resetFixtureApp(page);
		await forgetListings();
		// The baseline listing: every default release, 1.0.0 and 1.0.1 included.
		await runJob("AvailabilityRefreshJob");
	});

	test.afterEach(async ({ page }) => {
		await resetFixture(page);
		await forgetListings();
		// A fresh baseline, so no later spec inherits a withdrawn mark.
		await runJob("AvailabilityRefreshJob");
	});

	// @e2e withdrawn-and-revoked::an-admin-learns-the-running-version-was-withdrawn
	test("a release removed from the forge marks the running version withdrawn", async ({
		page,
	}) => {
		await withdrawRelease(page, "v1.0.0");

		await runJob("AvailabilityRefreshJob");

		expect((await storedRow()).installedWithdrawn).toBe(true);
		await openSettings(page);
		await openTab(page, "Apps");
		const card = page
			.locator("article")
			.filter({ has: page.getByText(FIXTURE_APP, { exact: true }) })
			.first();
		await expect(card.getByText("Version withdrawn by the publisher")).toBeVisible();
	});

	// @e2e withdrawn-and-revoked::a-stale-listing-does-not-bring-a-withdrawn-version-back
	test("a withdrawn version is refused with category withdrawn, even when listed again", async ({
		page,
	}) => {
		await withdrawRelease(page, "v1.0.1");
		await runJob("AvailabilityRefreshJob");
		expect(
			JSON.parse((await appConfigValue(page, `withdrawn.${FIXTURE_APP}`)) ?? "[]"),
		).toContain("1.0.1");

		// The forge lists 1.0.1 again, but no sweep has seen it: the record stands.
		await resetFixture(page);

		const { status, body } = await installFixture(page, "1.0.1");
		expect(status, JSON.stringify(body)).not.toBe(0);
		expect(body.category).toBe("withdrawn");
		expect(body.installStatus).not.toBe("installed");
		expect(body.hint).toBeTruthy();
		expect((await occ("config:app:get", FIXTURE_APP, "installed_version")).trim()).toBe("1.0.0");
	});

	// @e2e withdrawn-and-revoked::a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
	test("a listing that fails marks nothing withdrawn", async ({ page }) => {
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			status: 503,
		});

		await runJob("AvailabilityRefreshJob");

		expect(await appConfigValue(page, `withdrawn.${FIXTURE_APP}`)).toBeNull();
		expect((await storedRow()).installedWithdrawn ?? null).toBeNull();
	});
});
