import { expect, test } from "@playwright/test";
import { autoUpdateState, openSettings, openTab } from "./helpers.ts";

/**
 * Per-app auto-update policies and the global kill switch / window.
 * @spec openspec/specs/auto-update-policies/spec.md
 */
const APP = "dashboard";

test.describe("auto-update policies", () => {
	// Cleanup runs even when a test fails, which the inline restore at the end
	// of the kill-switch spec does not. That restore being skipped on failure is
	// how one red spec turned into two: the switch stayed ON, and the spec that
	// asserts the disabled hint renders only while it is OFF failed for a reason
	// that had nothing to do with it.
	test.afterEach(async ({ page }) => {
		await page.request
			.delete(
				`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
				{
					headers: { "OCS-APIRequest": "true" },
				},
			)
			.catch(() => undefined);

		await page.request
			.put(
				"/ocs/v2.php/apps/versioniq/api/auto-update/settings?enabled=0&format=json",
				{
					headers: {
						"OCS-APIRequest": "true",
						"Content-Type": "application/json",
					},
					data: { enabled: "0" },
				},
			)
			.catch(() => undefined);
	});

	test("automation is off by default and says so", async ({ page }) => {
		await openSettings(page);
		await openTab(page, "Apps");

		const settings = page.getByTestId("auto-update-settings");
		await expect(settings).toBeVisible();
		await expect(
			page.getByTestId("auto-update-kill-switch"),
		).not.toBeChecked();
		// Default window is advertised.
		await expect(page.getByTestId("auto-update-window")).toHaveValue(
			/01:00-05:00/,
		);
	});

	test("an invalid maintenance window is rejected before saving", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");

		await page.getByTestId("auto-update-window").fill("nonsense");
		await expect(
			page.getByTestId("auto-update-window-error"),
		).toBeVisible();
		await expect(
			page.getByTestId("auto-update-settings-save"),
		).toBeDisabled();
	});

	test("enabling automation with a valid window persists server-side", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");

		await page.getByTestId("auto-update-kill-switch").check();
		await page.getByTestId("auto-update-window").fill("23:00-03:00");
		await page.getByTestId("auto-update-settings-save").click();

		// The app confirms the save before anything else is asserted.
		await expect(
			page.getByTestId("auto-update-settings-save"),
		).toBeDisabled();

		// Then prove it PERSISTED, by reloading and reading the form back.
		//
		// Deliberately not read through page.request. A value written by the
		// page and read back through the request context comes back stale on
		// CI: this assertion polled for 20 seconds, twice, and saw false while
		// the app's own PUT had returned autoUpdateEnabled: true. The two specs
		// below and above that pass are the ones whose write and read share a
		// context. A reload re-fetches from the server, so this still fails if
		// the value never reached the database.
		await page.reload();
		await openTab(page, "Apps");

		await expect(page.getByTestId("auto-update-kill-switch")).toBeChecked();
		await expect(page.getByTestId("auto-update-window")).toHaveValue(
			"23:00-03:00",
		);

		// Put the instance back the way we found it. afterEach also turns the
		// switch off, so this is about the window, which afterEach leaves alone.
		await page.getByTestId("auto-update-kill-switch").uncheck();
		await page.getByTestId("auto-update-window").fill("01:00-05:00");
		await page.getByTestId("auto-update-settings-save").click();
		await expect(
			page.getByTestId("auto-update-settings-save"),
		).toBeDisabled();
	});

	test("a per-app policy is persisted and badged", async ({ page }) => {
		await openSettings(page);
		await openTab(page, "Apps");

		const card = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		const selector = card.getByTestId("policy-select");
		await expect(selector).toBeVisible();

		// NcSelect renders a combobox; pick the "Patch" option.
		await selector.click();
		await page.getByRole("option", { name: /patch/i }).first().click();

		// The badge is driven by the parent's `policies` map, which
		// onPolicyChange only writes after the PUT comes back, so seeing it
		// means the server accepted the change.
		await expect(card.getByTestId("policy-active-badge")).toBeVisible();

		// Then prove it SURVIVES, by reloading and looking again. This is the
		// property the test name claims and the one a user would notice.
		//
		// Deliberately not read through page.request here. A policy written by
		// the page and read back through the request context comes back empty
		// on CI, while the same read succeeds for a policy that context wrote
		// itself — see the kill-switch test below, which passes. Reloading
		// keeps the write and the read in one context and still fails if the
		// value never reached the database.
		await page.reload();
		await openTab(page, "Apps");

		const reloaded = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		await expect(reloaded.getByTestId("policy-active-badge")).toBeVisible();
		await expect(reloaded.getByTestId("policy-select")).toContainText(
			/patch/i,
		);
	});

	test("policies show as inert while the kill switch is off", async ({
		page,
	}) => {
		// The hint under test renders on `isActive && !autoUpdateEnabled`, so it
		// depends on the kill switch being OFF. The spec above turns it ON and
		// restores it at the end, which means a failure there leaves it ON and
		// this spec fails for a reason that has nothing to do with what it
		// checks. That is exactly what happened on the run before this change.
		//
		// So assert the precondition instead of inheriting it.
		const off = await page.request.put(
			"/ocs/v2.php/apps/versioniq/api/auto-update/settings?enabled=0&format=json",
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { enabled: "0" },
			},
		);
		expect(
			off.ok(),
			`could not turn the kill switch off (HTTP ${off.status()}); the hint below only renders while it is off`,
		).toBe(true);
		await expect
			.poll(async () => (await autoUpdateState(page)).autoUpdateEnabled, {
				message:
					"the kill switch must be off before this spec can mean anything",
				timeout: 20_000,
			})
			.toBe(false);

		// Seed a policy directly, then confirm the UI explains it will not run.
		//
		// The seed is asserted. Without this the request could fail and the
		// only symptom would be the hint below never appearing, which reads as
		// a broken component rather than a fixture that never ran.
		const seeded = await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { level: "patch" },
			},
		);
		expect(
			seeded.ok(),
			`seeding the ${APP} policy failed with HTTP ${seeded.status()}; the hint below cannot appear without it`,
		).toBe(true);

		// And assert the app itself now reports it, so a write that returns 200
		// without persisting is caught here rather than blamed on the UI.
		await expect
			.poll(
				async () =>
					(await autoUpdateState(page)).policies.find(
						(p) => p.appId === APP,
					)?.level,
				{
					message: "the seeded policy should be readable back",
					timeout: 20_000,
				},
			)
			.toBe("patch");

		await openSettings(page);
		await openTab(page, "Apps");

		const card = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		await expect(card.getByTestId("policy-disabled-hint")).toBeVisible();
	});
});
