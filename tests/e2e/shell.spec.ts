import { expect, test } from "@playwright/test";
import { openSettings, openTab } from "./helpers.ts";

/**
 * Admin settings shell: placement, tab surface and access control.
 * @spec openspec/specs/version-management/spec.md
 */
test.describe("admin settings shell", () => {
	test("renders every capability tab in the settings section", async ({
		page,
	}) => {
		await openSettings(page);

		const tablist = page.getByRole("tablist", {
			name: "Versioniq sections",
		});
		for (const name of [
			"Apps",
			"History",
			"Sources",
			"Tokens",
			"Trusted sources",
			"Discover",
			"Artifact cache",
		]) {
			await expect(
				tablist.getByRole("tab", { name, exact: true }),
			).toBeVisible();
		}
		// Apps is the default landing tab.
		await expect(
			tablist.getByRole("tab", { name: "Apps", exact: true }),
		).toHaveAttribute("aria-selected", "true");
	});

	test("tabs are keyboard navigable and expose correct ARIA state", async ({
		page,
	}) => {
		await openSettings(page);
		const tablist = page.getByRole("tablist", {
			name: "Versioniq sections",
		});

		await tablist.getByRole("tab", { name: "Apps", exact: true }).focus();
		await page.keyboard.press("ArrowRight");
		await expect(
			tablist.getByRole("tab", { name: "History", exact: true }),
		).toHaveAttribute("aria-selected", "true");
		await expect(
			page.getByRole("tabpanel", { name: "History" }),
		).toBeVisible();
	});

	test("every control exposes an accessible name (WCAG 4.1.2)", async ({
		page,
	}) => {
		await openSettings(page);

		// Buttons inside our settings section must never be nameless.
		const section = page
			.locator("#versioniq-panel, [role=tabpanel]")
			.first();
		await expect(section).toBeVisible();
		const buttons = await page
			.getByRole("tabpanel")
			.getByRole("button")
			.all();
		for (const button of buttons) {
			const name = (
				(await button.getAttribute("aria-label")) ??
				(await button.innerText())
			).trim();
			expect(name, "button without accessible name").not.toBe("");
		}
	});

	test("the app list loads installed apps", async ({ page }) => {
		await openSettings(page);
		const panel = await openTab(page, "Apps");
		// A default Nextcloud always ships these.
		await expect(
			panel.getByText("dashboard", { exact: true }).first(),
		).toBeVisible();
		await expect(
			panel.getByRole("button", { name: "Choose app" }).first(),
		).toBeVisible();
	});

	test("unauthenticated callers cannot reach the settings API", async ({
		playwright,
		baseURL,
	}) => {
		// A fresh context — the default `request` fixture carries the stored admin
		// session, which would make this assertion vacuously pass.
		const anon = await playwright.request.newContext({
			baseURL,
			storageState: undefined,
		});
		const res = await anon.get(
			"/ocs/v2.php/apps/versioniq/api/apps?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		// Nextcloud answers unauthenticated OCS calls with 401 (or a 200 envelope
		// carrying a 401 statuscode); either way the app list must not be served.
		const body = await res.text();
		expect(body).not.toContain('"apps"');
		await anon.dispose();
	});
});
