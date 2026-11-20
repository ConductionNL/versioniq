// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, request as playwrightRequest, test } from "@playwright/test";
import { NC_ADMIN_USER } from "../../playwright.config";
import { execInInstance, occ, openSettings, openTab } from "./helpers.ts";

const FEED_URL = "/index.php/apps/versioniq/feed/releases.rss";
const RELEASE_LOG_KEY = "release_alerts.log";
const LOGGED_RELEASE = {
	appId: "hermiq",
	version: "1.5.0",
	sourceId: "github:ConductionNL/hermiq",
	compatible: true,
	releasedAt: null,
	detectedAt: 1_790_000_000,
};

/**
 * Creates an app password for `user` with occ and returns it. Without a
 * terminal occ asks for no login password; the token still signs in.
 */
async function createAppPassword(user: string): Promise<string> {
	const { code, stdout, stderr } = await execInInstance([
		"php",
		"occ",
		"user:auth-tokens:add",
		user,
	]);
	const token = stdout.trim().split("\n").pop()?.trim() ?? "";
	expect(code, `user:auth-tokens:add failed: ${stderr}`).toBe(0);
	expect(token, "occ printed no app password").not.toBe("");
	return token;
}

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
			"Advisories",
			"History",
			"Sources",
			"Tokens",
			"Trusted sources",
			"Discover",
			"Artifact cache",
			"Settings",
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
			tablist.getByRole("tab", { name: "Advisories", exact: true }),
		).toHaveAttribute("aria-selected", "true");
		await expect(
			page.getByRole("tabpanel", { name: "Advisories" }),
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

/**
 * The release feed a feed reader polls with an admin's app password.
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-new-releases-are-available-as-an-rss-feed-for-admins
 */
test.describe("release feed", () => {
	let previousLog: string | null = null;

	test.beforeEach(async () => {
		const { code, stdout } = await execInInstance([
			"php",
			"occ",
			"config:app:get",
			"versioniq",
			RELEASE_LOG_KEY,
		]);
		previousLog = code === 0 ? stdout.trim() : null;
		await occ(
			"config:app:set",
			"versioniq",
			RELEASE_LOG_KEY,
			`--value=${JSON.stringify([LOGGED_RELEASE])}`,
		);
	});

	test.afterEach(async () => {
		if (previousLog === null) {
			await occ("config:app:delete", "versioniq", RELEASE_LOG_KEY);
			return;
		}
		await occ(
			"config:app:set",
			"versioniq",
			RELEASE_LOG_KEY,
			`--value=${previousLog}`,
		);
	});

	// @e2e release-alerts::a-feed-reader-signs-in-with-an-app-password
	test("an admin's app password reads the feed as RSS 2.0", async ({
		baseURL,
	}) => {
		const appPassword = await createAppPassword(NC_ADMIN_USER);
		// No stored session: the app password is the only credential.
		const reader = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: {
				username: NC_ADMIN_USER,
				password: appPassword,
				send: "always",
			},
		});
		try {
			const response = await reader.get(FEED_URL);
			expect(response.status()).toBe(200);
			expect(response.headers()["content-type"]).toContain(
				"application/rss+xml",
			);
			const body = await response.text();
			expect(body).toContain('<rss version="2.0">');
			expect(body).toContain("<title>hermiq 1.5.0</title>");
			expect(body).toContain(
				"<category>github:ConductionNL/hermiq</category>",
			);
			expect(body).toMatch(
				/<link>[^<]*settings\/admin\/versioniq<\/link>/,
			);
		} finally {
			await reader.dispose();
		}
	});

	// @e2e release-alerts::the-feed-is-not-public
	test("an anonymous caller or a non-admin gets no release", async ({
		baseURL,
	}) => {
		await occ("user:delete", "feed-e2e-user");
		await execInInstance(
			["php", "occ", "user:add", "--password-from-env", "feed-e2e-user"],
			{ env: { OC_PASS: "feedUserPass123" } },
		);
		const anonymous = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
		});
		const nonAdmin = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: {
				username: "feed-e2e-user",
				password: await createAppPassword("feed-e2e-user"),
				send: "always",
			},
		});
		try {
			for (const context of [anonymous, nonAdmin]) {
				const response = await context.get(FEED_URL, {
					maxRedirects: 0,
				});
				expect(response.status()).not.toBe(200);
				const body = await response.text();
				expect(body).not.toContain("hermiq");
				expect(body).not.toContain("1.5.0");
			}
		} finally {
			await anonymous.dispose();
			await nonAdmin.dispose();
			await occ("user:delete", "feed-e2e-user");
		}
	});
});
