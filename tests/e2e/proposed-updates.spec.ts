// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import {
	FIXTURE_APP,
	fixtureAvailable,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
	runJob,
} from "./helpers.ts";

const OCS = { "OCS-APIRequest": "true", "Content-Type": "application/json" };

/**
 * Proposed updates (autoupdate-proposed-updates, task 4.4): the update check
 * works out what the next automatic run would install, the Apps tab lists it
 * with its release notes, and an admin approves it there. An app that is
 * pinned gets no proposal and says why.
 *
 * Runs against the forge fixture app (installed 1.0.0, patch 1.0.1 listed),
 * as the signed-in admin the suite's storage state carries.
 *
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 */
test.describe("proposed updates", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
	});

	test.afterEach(async ({ page }) => {
		await occ("config:app:delete", "versioniq", "auto_update.proposals");
		await occ("config:app:delete", "versioniq", "auto_update.proposal_decisions");
		await page.request
			.delete(`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/policy?format=json`, { headers: OCS })
			.catch(() => undefined);
		await page.request
			.delete(`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/pin?format=json`, { headers: OCS })
			.catch(() => undefined);
	});

	async function requireApprovalOnPatch(page: Page): Promise<void> {
		const put = await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/policy?format=json`,
			{ headers: OCS, data: { level: "patch", requireApproval: "1" } },
		);
		expect(put.ok(), "setting the policy should succeed").toBeTruthy();
	}

	test("the update check proposes 1.0.1 with its notes, and an admin approves it on the page", async ({
		page,
	}) => {
		// @e2e auto-update-policies::an-admin-approves-a-proposed-update
		await requireApprovalOnPatch(page);
		await runJob("AvailabilityRefreshJob");

		await openSettings(page);
		const apps = await openTab(page, "Apps");
		const proposal = apps.getByTestId(`proposal-${FIXTURE_APP}`);
		await expect(proposal).toBeVisible();
		await expect(proposal).toContainText("1.0.0");
		await expect(proposal).toContainText("1.0.1");
		await expect(proposal.getByTestId("proposal-status")).toContainText("Awaiting approval since");

		await proposal.getByTestId("proposal-notes-toggle").click();
		await expect(proposal.getByTestId("proposal-notes")).not.toBeEmpty();

		await proposal.getByTestId("proposal-approve").click();
		await expect(proposal.getByTestId("proposal-status")).toContainText("Approved by admin");

		// The decision is stored, not only shown.
		const listed = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/auto-update/proposals?format=json",
			{ headers: OCS },
		);
		expect(JSON.stringify(await listed.json())).toContain('"approvedBy":"admin"');
	});

	test("a pinned app gets no proposal and the panel says why", async ({ page }) => {
		// @e2e auto-update-policies::a-pinned-app-is-not-proposed
		await requireApprovalOnPatch(page);
		const pin = await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/pin?format=json`,
			{ headers: OCS, data: { reason: "proposed-updates e2e" } },
		);
		expect(pin.ok(), "pinning the fixture app should succeed").toBeTruthy();
		await runJob("AvailabilityRefreshJob");

		await openSettings(page);
		const apps = await openTab(page, "Apps");
		await expect(apps.getByTestId(`proposal-${FIXTURE_APP}`)).toHaveCount(0);
		await expect(apps.getByTestId(`proposal-reason-${FIXTURE_APP}`)).toContainText(
			"Pinned, so it is not updated automatically.",
		);
	});
});
