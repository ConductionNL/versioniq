import type { APIRequestContext } from "@playwright/test";

import { expect, test } from "@playwright/test";
import { openSettings, openTab, SETTINGS_URL } from "./helpers.ts";

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations tab over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from `lib/Settings/connections.json`, with `app` equal to
 * `versioniq`. Versioniq writes no row: a request reports what it met, and
 * integriq decides the status. So this spec needs openregister and integriq
 * installed and synced, and reads the rows from
 * `/apps/openregister/api/objects/integriq/app_connection?app=versioniq`.
 *
 * `app` is a BARE filter key. The objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * WHAT A RED HERE USUALLY MEANS. An empty list in the first test means
 * integriq has not synced the declaration, or refused it whole. A missing
 * Integrations tab means `Admin::getForm()` found integriq disabled.
 *
 * Statuses are read from the API, and rows are found by key, because the
 * status a row carries depends on which requests this instance has made.
 */

/** Integriq's objects endpoint for this app's connection rows. */
const CONNECTIONS_API =
	"/index.php/apps/openregister/api/objects/integriq/app_connection?app=versioniq&_limit=50";

/** The declared keys, in declared order. */
const DECLARED = ["appstore", "github", "advisories"];

/** Headers for the JSON API calls. */
const JSON_HEADERS = { "OCS-APIRequest": "true", Accept: "application/json" };

/**
 * This app's connection rows, keyed by connection key.
 *
 * @param request An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(
	request: APIRequestContext,
): Promise<Record<string, Record<string, unknown>>> {
	const res = await request.get(CONNECTIONS_API, { headers: JSON_HEADERS });
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy();
	const body = await res.json();
	const byKey: Record<string, Record<string, unknown>> = {};
	for (const row of (body.results ?? []) as Record<string, unknown>[]) {
		// A row from another app here means the bare filter was dropped.
		expect(String(row.app), "a connection row from another app").toBe("versioniq");
		byKey[String(row.key)] = row;
	}
	return byKey;
}

test.describe("Integrations over the connection registry", () => {
	// @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#the-tab-lists-only-the-rows-of-versioniq
	test("lists the three declared connections, all of them versioniq's", async ({
		page,
	}) => {
		const byKey = await rowsByKey(page.request);
		expect(Object.keys(byKey).sort()).toEqual([...DECLARED].sort());
		expect(Object.keys(byKey)).not.toContain("codeberg");

		await openSettings(page);
		const panel = await openTab(page, "Integrations");

		await expect(panel.getByRole("heading", { name: "Integrations" })).toBeVisible();
		const rows = panel.getByTestId("integrations-row");
		await expect(rows).toHaveCount(DECLARED.length);
		await expect(rows.nth(0)).toHaveAttribute("data-key", "appstore");
		await expect(rows.nth(1)).toHaveAttribute("data-key", "github");
		await expect(rows.nth(2)).toHaveAttribute("data-key", "advisories");

		// Each row shows the status integriq holds for it, never a raw enum.
		for (const key of DECLARED) {
			const row = panel.locator(`[data-testid="integrations-row"][data-key="${key}"]`);
			await expect(row).toHaveAttribute("data-status", String(byKey[key]?.status ?? ""));
		}
	});

	// @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#add-integration-goes-to-integriq
	test("sends Add integration to integriq instead of offering a form", async ({
		page,
	}) => {
		await openSettings(page);
		const panel = await openTab(page, "Integrations");

		await expect(panel.getByRole("textbox")).toHaveCount(0);
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=versioniq&link=1$/, {
				timeout: 30_000,
			}),
			panel.getByTestId("integrations-add").click(),
		]);
	});

	// @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#a-settings-link-opens-the-tab-that-holds-its-anchor
	test("a settings link opens the tab that holds its anchor", async ({ page }) => {
		const byKey = await rowsByKey(page.request);
		expect(String(byKey.github?.settingsUrl ?? "")).toBe(
			"/settings/admin/versioniq#section-sources",
		);

		await page.goto(`${SETTINGS_URL}#section-sources`, { waitUntil: "domcontentloaded" });
		const tablist = page.getByRole("tablist", { name: "Versioniq sections" });
		await expect(tablist.getByRole("tab", { name: "Sources", exact: true })).toHaveAttribute(
			"aria-selected",
			"true",
		);
		await expect(page.locator("#section-sources")).toBeVisible();

		// A hash change on the open page moves the tab too.
		await page.evaluate(() => {
			window.location.hash = "section-advisories";
		});
		await expect(tablist.getByRole("tab", { name: "Apps", exact: true })).toHaveAttribute(
			"aria-selected",
			"true",
		);
		await expect(page.locator("#section-advisories")).toBeVisible();
	});
});
