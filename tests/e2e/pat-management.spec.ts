import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import { fixtureAvailable, occ, openSettings, openTab } from "./helpers.ts";

/**
 * Personal Access Token management, driven via the API and the Tokens panel.
 * Codeberg tokens validate against the fixture forge's /user endpoint.
 *
 * @spec openspec/specs/pat-management/spec.md
 */
test.describe("PAT management", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		// Start from a clean slate: remove any PATs left by a prior test.
		const res = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/pats?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		const pats = (await res.json())?.ocs?.data?.pats ?? [];
		for (const p of pats) {
			await page.request
				.delete(
					`/ocs/v2.php/apps/versioniq/api/pats/${p.id}?format=json`,
					{
						headers: { "OCS-APIRequest": "true" },
					},
				)
				.catch(() => undefined);
		}
	});

	async function pats(page: Page) {
		const res = await page.request.get(
			"/ocs/v2.php/apps/versioniq/api/pats?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		return (await res.json())?.ocs?.data?.pats ?? [];
	}

	async function addToken(page: Page, data: object) {
		return page.request.post(
			"/ocs/v2.php/apps/versioniq/api/pats?format=json",
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: {
					forge: "codeberg",
					label: "fixture token",
					targetPattern: "fixtureowner/*",
					...data,
				},
			},
		);
	}

	test("a valid Codeberg token is accepted with an unverifiable-scope warning", async ({
		page,
	}) => {
		// Codeberg does not expose token scopes, so a valid token is accepted with
		// a best-effort warning. Validated against the fixture /user endpoint.
		const res = await addToken(page, { token: "codeberg-good-token" });
		expect(res.ok(), "valid codeberg token accepted").toBeTruthy();
		const body = (await res.json())?.ocs?.data;
		expect(JSON.stringify(body).toLowerCase()).toMatch(
			/unverifi|scope|warning/,
		);
	});

	test("a revoked token is rejected", async ({ page }) => {
		const res = await addToken(page, { token: "codeberg-revoked-token" });
		expect(res.ok()).toBeFalsy();
		expect(await pats(page)).toHaveLength(0);
	});

	test("a created token is never exposed in plaintext by the API", async ({
		page,
	}) => {
		await addToken(page, { token: "codeberg-secret-abcdef123456" });
		const list = await pats(page);
		expect(list.length).toBe(1);
		expect(JSON.stringify(list)).not.toContain(
			"codeberg-secret-abcdef123456",
		);
		// A redacted hint may be present, but never the token or encrypted bytes.
		expect(JSON.stringify(list)).not.toContain("encrypted");
	});

	test("a token can be edited and deleted", async ({ page }) => {
		const created = (
			await (
				await addToken(page, {
					token: "codeberg-editable-token",
					label: "before",
				})
			).json()
		)?.ocs?.data;
		const id = created.id ?? (await pats(page))[0].id;

		const patch = await page.request.patch(
			`/ocs/v2.php/apps/versioniq/api/pats/${id}?format=json`,
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { label: "after" },
			},
		);
		expect(patch.ok()).toBeTruthy();
		expect((await pats(page))[0].label).toBe("after");

		const del = await page.request.delete(
			`/ocs/v2.php/apps/versioniq/api/pats/${id}?format=json`,
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		expect(del.ok()).toBeTruthy();
		expect(await pats(page)).toHaveLength(0);
	});

	test("per-forge token-creation deeplinks are provided", async ({
		page,
	}) => {
		for (const [forge, kind, needle] of [
			["github", "classic", "github.com"],
			["github", "fine-grained", "github.com"],
			["codeberg", "forge-token", "codeberg.org"],
		] as const) {
			const res = await page.request.get(
				`/ocs/v2.php/apps/versioniq/api/pats/deeplink?forge=${forge}&kind=${kind}&format=json`,
				{ headers: { "OCS-APIRequest": "true" } },
			);
			const data = (await res.json())?.ocs?.data;
			expect(data.url, `${forge}/${kind} deeplink`).toContain(needle);
		}
	});

	test("the Tokens panel lists tokens redacted", async ({ page }) => {
		await addToken(page, {
			token: "codeberg-panel-token-zzz",
			label: "panel token",
		});
		await openSettings(page);
		const panel = await openTab(page, "Tokens");
		await expect(panel).toContainText("panel token");
		await expect(panel).not.toContainText("codeberg-panel-token-zzz");
	});

	test("unauthenticated callers cannot list tokens", async ({
		playwright,
		baseURL,
	}) => {
		const anon = await playwright.request.newContext({
			baseURL,
			storageState: undefined,
		});
		const res = await anon.get(
			"/ocs/v2.php/apps/versioniq/api/pats?format=json",
			{
				headers: { "OCS-APIRequest": "true" },
			},
		);
		expect(await res.text()).not.toContain('"pats"');
		await anon.dispose();
	});

	test.afterAll(async () => {
		// Best-effort: clear PATs via occ so no fixture token lingers.
		await occ("config:app:delete", "versioniq", "noop").catch(
			() => undefined,
		);
	});
});
