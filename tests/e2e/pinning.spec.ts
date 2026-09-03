import { expect, test } from "@playwright/test";
import {
	appConfigValue,
	chooseApp,
	openSettings,
	openTab,
	versionsLoaded,
} from "./helpers.ts";

/**
 * Version pinning and its audit-trail integration, driven through the UI.
 * Each test cleans up the pin it creates so the instance is left as found.
 *
 * @spec openspec/specs/version-pinning/spec.md
 * @spec openspec/specs/audit-trail/spec.md
 */
const APP = "dashboard";

test.describe("version pinning", () => {
	test.afterEach(async ({ page }) => {
		// Best-effort cleanup: remove any pin this spec may have left behind.
		await page.request
			.delete(
				`/ocs/v2.php/apps/versioniq/api/app/${APP}/pin?format=json`,
				{
					headers: { "OCS-APIRequest": "true" },
				},
			)
			.catch(() => undefined);
	});

	test("pin the installed version, then unpin — both audited", async ({
		page,
	}) => {
		await openSettings(page);
		await chooseApp(page, APP);
		await versionsLoaded(page);

		// --- Pin -------------------------------------------------------------
		await page.getByRole("button", { name: "Pin this version" }).click();

		const dialog = page.getByRole("dialog");
		await expect(dialog).toBeVisible();
		// The pin dialog must be honest that a pin is monitored, not enforced
		// against Nextcloud's own updater.
		await expect(dialog).toContainText("Nextcloud");
		await dialog
			.getByRole("textbox", { name: "Reason (optional)" })
			.fill("e2e pin check");
		await dialog.getByRole("button", { name: "Pin", exact: true }).click();
		await expect(dialog).toBeHidden();

		// Persisted server-side with attribution.
		const stored = await appConfigValue(page, `pin.${APP}`);
		expect(stored, "pin should be persisted in app config").toBeTruthy();
		const pin = JSON.parse(stored as string);
		expect(pin.version).toBeTruthy();
		expect(pin.pinnedBy).toBe("admin");
		expect(pin.pinnedAt).toMatch(/^\d{4}-\d{2}-\d{2}T/);
		expect(pin.reason).toBe("e2e pin check");

		// Surfaced as a badge with an unpin affordance.
		await expect(
			page
				.getByTestId("pin-badge")
				.or(page.getByTestId("pin-badge-detail"))
				.first(),
		).toBeVisible();

		// --- Audited ---------------------------------------------------------
		await openTab(page, "History");
		const pinRow = page
			.getByTestId("history-row")
			.filter({ has: page.locator('[data-operation="pin"]') })
			.or(page.locator("[data-testid=history-row][data-operation=pin]"));
		await expect(pinRow.first()).toBeVisible();
		await expect(pinRow.first()).toContainText("admin");
		await expect(pinRow.first()).toContainText(APP);

		// --- Unpin -----------------------------------------------------------
		await openTab(page, "Apps");
		await page.getByRole("button", { name: "Unpin" }).first().click();
		await expect
			.poll(async () => appConfigValue(page, `pin.${APP}`), {
				message: "pin should be cleared after unpin",
				timeout: 20_000,
			})
			.toBeNull();

		await openTab(page, "History");
		await expect(
			page
				.locator("[data-testid=history-row][data-operation=unpin]")
				.first(),
		).toBeVisible();
	});

	test("a pinned app shows who pinned it and when", async ({ page }) => {
		// Seed a pin through the API, then assert the UI presents it.
		const put = await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${APP}/pin?format=json`,
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { reason: "seeded by e2e" },
			},
		);
		expect(put.ok(), "seeding a pin via API should succeed").toBeTruthy();

		// The badge renders `v-if="pinFor(app.id)"`, fed by GET /api/pins. So
		// "element(s) not found" has three quite different causes — the pin was
		// never stored, the list endpoint does not return it, or the component
		// did not render it — and the locator alone cannot tell them apart.
		// Reading the API first turns one opaque failure into a statement about
		// WHICH link of that chain broke.
		const pinsList = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/pins?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		const listed = (await pinsList.json())?.ocs?.data?.pins ?? [];
		expect(
			listed.map((p: { appId: string }) => p.appId),
			`GET /api/pins must list the pin just seeded for "${APP}" — if this passes and the badge below does not appear, the break is in the component, not the API`,
		).toContain(APP);

		await openSettings(page);
		await openTab(page, "Apps");

		// Located by the card's OWN app id, not by its visible text. The card
		// renders `{{ app.label }}`, so a text match proves a label contains the
		// string — it says nothing about `app.id`, which is the value the
		// badge's `pinFor(app.id)` actually keys on. Those are the two sides of
		// the comparison under test, so matching on the wrong one would make a
		// green assertion meaningless.
		const card = page.locator(`article[data-app-id="${APP}"]`);
		await expect(
			card,
			`no app card has data-app-id="${APP}" — the pin is keyed by appId, so if the card's id differs from the API's appId that mismatch IS the bug`,
		).toBeVisible();

		const badge = page.getByTestId("pin-badge").first();
		await expect(
			badge,
			`the API lists a pin for "${APP}" and its card IS rendered (both asserted above), so the badge's own v-if="pinFor(app.id)" is what did not match — compare the pin's appId against the card's app.id`,
		).toBeVisible();
		await expect(badge).toContainText("Pinned");
		// Attribution is carried in the title so hovering explains the badge.
		await expect(badge).toHaveAttribute("title", /admin/);
	});
});
