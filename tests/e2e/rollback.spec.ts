// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import { NC_ADMIN_PASS } from "../../playwright.config";
import {
	FIXTURE_APP,
	fixtureAvailable,
	installFixture,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
	sqlExec,
} from "./helpers.ts";

/**
 * Rolling the instance back to a snapshot (install-bulk-rollback 3.3).
 *
 * fixtureapp is installed at 1.0.1, a named snapshot is taken from the
 * History tab, 1.1.0 is installed, and the rollback to the snapshot brings
 * the app back to 1.0.1. The run is logged as two `bulk_rollback` rows, one
 * when it starts and one when it ends, which the History tab shows.
 *
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */

const SNAPSHOT_NAME = "e2e before 1.1.0";

/** Answers Nextcloud's password confirmation when it asks for it. */
async function confirmPasswordIfAsked(page: Page) {
	const field = page.locator('[role="dialog"] input[type="password"]').first();
	if (await field.waitFor({ state: "visible", timeout: 3_000 }).then(() => true, () => false)) {
		await field.fill(NC_ADMIN_PASS);
		await field.press("Enter");
		await expect(field).toBeHidden();
	}
}

async function installed () {
  return (await occ("config:app:get", FIXTURE_APP, "installed_version")).trim()
}

test.describe("roll back to a snapshot", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
		// The rows of earlier runs would make the count below meaningless.
		await sqlExec("DELETE FROM oc_app_versions_audit WHERE operation = 'bulk_rollback'");
	});

	test.afterEach(async ({ page }) => {
		// Delete the named snapshot this test took: named ones are never pruned.
		const list = await occ("versioniq:snapshots", "--json");
		const match = list.match(/\[[\s\S]*\]\s*$/);
		const index: Array<{ id: string; name?: string | null }> = match ? JSON.parse(match[0]) : [];
		for (const snapshot of index.filter((entry) => entry.name === SNAPSHOT_NAME)) {
			await page.request
				.delete(`/ocs/v2.php/apps/versioniq/api/snapshots/${snapshot.id}?format=json`, {
					headers: { "OCS-APIRequest": "true" },
				})
				.catch(() => undefined);
		}
	});

	// @e2e install-bulk-rollback::an-admin-rolls-back-to-a-snapshot
	test("an admin takes a snapshot between two installs and rolls back to it", async ({
		page,
	}) => {
		const first = await installFixture(page, "1.0.1");
		expect(first.status, JSON.stringify(first.body)).toBe(0);
		expect(await installed()).toBe("1.0.1");

		await openSettings(page);
		let history = await openTab(page, "History");
		const panel = history.getByRole("region", { name: "Roll back" });
		await panel.getByLabel("Snapshot name (optional)").fill(SNAPSHOT_NAME);
		await panel.getByRole("button", { name: "Take a snapshot" }).click();
		await confirmPasswordIfAsked(page);
		const snapshot = panel.locator("li[data-snapshot]").filter({ hasText: SNAPSHOT_NAME });
		await expect(snapshot).toHaveCount(1);

		const second = await installFixture(page, "1.1.0");
		expect(second.status, JSON.stringify(second.body)).toBe(0);
		expect(await installed()).toBe("1.1.0");

		await openSettings(page);
		history = await openTab(page, "History");
		const reloaded = history
			.getByRole("region", { name: "Roll back" })
			.locator("li[data-snapshot]")
			.filter({ hasText: SNAPSHOT_NAME });
		await reloaded.getByRole("button", { name: "Plan", exact: true }).click();

		const dialog = page.getByRole("dialog", { name: "Roll back to a snapshot" });
		await expect(dialog).toBeVisible();
		const step = dialog.locator(`tr[data-app="${FIXTURE_APP}"]`);
		await expect(step).toContainText("1.1.0");
		await expect(step).toContainText("1.0.1");

		await dialog.getByRole("button", { name: "Run", exact: true }).click();
		await confirmPasswordIfAsked(page);
		await expect(dialog.getByText(/succeeded, \d+ failed, \d+ skipped/)).toBeVisible({ timeout: 120_000 });
		await expect(dialog.getByText(/^0 succeeded/)).toHaveCount(0);

		expect(await installed(), "rolled back to the snapshot's version").toBe("1.0.1");

		await dialog.getByRole("button", { name: "Close", exact: true }).click();
		await openSettings(page);
		history = await openTab(page, "History");
		await expect(
			history.locator('[data-testid="history-row"][data-operation="bulk_rollback"]'),
		).toHaveCount(2);
	});
});
