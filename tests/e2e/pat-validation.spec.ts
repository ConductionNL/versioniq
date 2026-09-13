import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import {
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	fixtureControl,
	occ,
	resetFixtureApp,
	sql,
	sqlExec,
	tsOffset,
} from "./helpers.ts";

/**
 * PAT validation, private-repo auth, ownership, and lifecycle — driven against
 * the fixture forge (github + codeberg base URLs both point at it).
 *
 * @spec openspec/specs/pat-management/spec.md
 */
test.describe("PAT validation & lifecycle", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		// Clear PATs owned by admin so listings are deterministic.
		const list =
			(
				await (
					await page.request.get(
						"/ocs/v2.php/apps/versioniq/api/pats?format=json",
						{ headers: { "OCS-APIRequest": "true" } },
					)
				).json()
			)?.ocs?.data?.pats ?? [];
		for (const p of list) {
			await page.request
				.delete(
					`/ocs/v2.php/apps/versioniq/api/pats/${p.id}?format=json`,
					{ headers: { "OCS-APIRequest": "true" } },
				)
				.catch(() => undefined);
		}
	});

	function addPat(page: Page, data: object) {
		return page.request.post(
			"/ocs/v2.php/apps/versioniq/api/pats?format=json",
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { targetPattern: "fixtureowner/*", ...data },
			},
		);
	}

	test("a classic GitHub PAT with only repo scope is accepted", async ({
		page,
	}) => {
		// github base points at the fixture; /user returns X-OAuth-Scopes: repo.
		const res = await addPat(page, {
			forge: "github",
			label: "repo-scope",
			token: "ghp_scope-repo-000000000000000000",
		});
		expect(res.ok(), "repo-only classic PAT accepted").toBeTruthy();
	});

	test("a classic GitHub PAT with over-broad scope is rejected", async ({
		page,
	}) => {
		const res = await addPat(page, {
			forge: "github",
			label: "admin-scope",
			token: "ghp_scope-admin-00000000000000000",
		});
		expect(res.ok(), "over-broad classic PAT rejected").toBeFalsy();
		expect(
			JSON.stringify((await res.json())?.ocs?.data ?? {}).toLowerCase(),
		).toMatch(/scope|admin|write/);
	});

	test("a token expiry is captured from the forge response", async ({
		page,
	}) => {
		await addPat(page, {
			forge: "github",
			label: "expiring-token",
			token: "ghp_expires-000000000000000000000",
		});
		const exp = (
			await sql(
				"SELECT expires_at FROM oc_versioniq_pats WHERE label='expiring-token'",
			)
		).trim();
		expect(exp, "expiry parsed and stored").toMatch(/2026-08-15/);
	});

	test("the stored token is encrypted at rest, never plaintext", async ({
		page,
	}) => {
		await addPat(page, {
			forge: "codeberg",
			label: "enc",
			token: "codeberg-plaintext-marker-zzz999",
		});
		const enc = (
			await sql(
				"SELECT encrypted_token FROM oc_versioniq_pats WHERE label='enc'",
			)
		).trim();
		expect(enc.length, "a value is stored").toBeGreaterThan(0);
		expect(enc, "not the plaintext token").not.toContain(
			"codeberg-plaintext-marker-zzz999",
		);
	});

	test("a PAT-gated private repo is reachable with a matching token, not without", async ({
		page,
	}) => {
		await resetFixtureApp(page);
		// Make the fixture repo require auth.
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			requireAuth: true,
		});

		// Without a token: the private repo 404s → no versions.
		const noauth = await page.request.get(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions?source=codeberg:fixtureowner/fixtureapp&format=json`,
			{ headers: { "OCS-APIRequest": "true" } },
		);
		expect(
			((await noauth.json())?.ocs?.data?.availableVersions ?? []).length,
		).toBe(0);

		// With a matching codeberg PAT: the token is attached and versions list.
		await addPat(page, {
			forge: "codeberg",
			label: "private",
			token: "codeberg-private-repo-token-000",
		});
		const withauth = await page.request.get(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions?source=codeberg:fixtureowner/fixtureapp&format=json`,
			{ headers: { "OCS-APIRequest": "true" } },
		);
		expect(
			((await withauth.json())?.ocs?.data?.availableVersions ?? [])
				.length,
		).toBeGreaterThan(0);
	});

	test("an expired PAT is skipped, falling back to the unauthenticated path", async ({
		page,
	}) => {
		await resetFixtureApp(page);
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			requireAuth: true,
		});
		// A matching codeberg PAT that we then age into the past.
		await addPat(page, {
			forge: "codeberg",
			label: "expired",
			token: "codeberg-expired-token-000",
		});
		await sqlExec(
			`UPDATE oc_versioniq_pats SET expires_at = '${tsOffset(-1)}' WHERE label='expired'`,
		);

		// The expired PAT must not be attached → the private repo 404s → no versions.
		const res = await page.request.get(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/versions?source=codeberg:fixtureowner/fixtureapp&format=json`,
			{ headers: { "OCS-APIRequest": "true" } },
		);
		expect(
			((await res.json())?.ocs?.data?.availableVersions ?? []).length,
			"expired PAT skipped → unauthenticated → private repo hidden",
		).toBe(0);
	});

	test("a PAT owned by another admin is neither listed nor deletable", async ({
		page,
	}) => {
		// Seed a PAT owned by a different admin, not shared.
		//
		// `false`, not `0`, for shared_with_admins. SQLite takes either; pgsql
		// types that column boolean and refuses the integer with
		//   SQLSTATE[42804] column "shared_with_admins" is of type boolean
		// The failed INSERT then made the id lookup below return an empty
		// string, which the NEXT statement interpolated into `WHERE id=` — one
		// type mismatch showing up as a syntax error two queries later.
		await sqlExec(
			`INSERT INTO oc_versioniq_pats (owner_uid, label, target_pattern, kind, forge, encrypted_token, token_hint, shared_with_admins, warned_thresholds, created_at) VALUES ('otheradmin','theirs','x/*','forge-token','codeberg','enc','abcd...wxyz',false,'[]', '${tsOffset()}')`,
		);
		const id = (
			await sql("SELECT id FROM oc_versioniq_pats WHERE label='theirs'")
		).trim();

		// admin does not see a non-shared PAT owned by someone else.
		const list =
			(
				await (
					await page.request.get(
						"/ocs/v2.php/apps/versioniq/api/pats?format=json",
						{ headers: { "OCS-APIRequest": "true" } },
					)
				).json()
			)?.ocs?.data?.pats ?? [];
		expect(
			list.find((p: any) => String(p.id) === id),
			"not listed to a non-owner",
		).toBeFalsy();

		// admin cannot delete it.
		const del = await page.request.delete(
			`/ocs/v2.php/apps/versioniq/api/pats/${id}?format=json`,
			{ headers: { "OCS-APIRequest": "true" } },
		);
		expect([403, 404]).toContain(del.status());
		expect(
			Number(
				await sql(
					`SELECT count(*) FROM oc_versioniq_pats WHERE id=${id}`,
				),
			),
			"still present",
		).toBe(1);

		await sqlExec(`DELETE FROM oc_versioniq_pats WHERE id=${id}`);
	});

	test("deleting a user sweeps their PATs", async () => {
		const userAdd = () =>
			execInInstance(
				[
					"php",
					"occ",
					"user:add",
					"--password-from-env",
					"pat-sweep-user",
				],
				{ env: { OC_PASS: "sweepUserPass123" } },
			);

		await occ("user:delete", "pat-sweep-user"); // clean any prior run
		await userAdd();
		await sqlExec(
			`INSERT INTO oc_versioniq_pats (owner_uid, label, target_pattern, kind, forge, encrypted_token, token_hint, shared_with_admins, warned_thresholds, created_at) VALUES ('pat-sweep-user','swept','x/*','forge-token','codeberg','enc','abcd...wxyz',false,'[]', '${tsOffset()}')`,
		);
		expect(
			Number(
				await sql(
					"SELECT count(*) FROM oc_versioniq_pats WHERE owner_uid='pat-sweep-user'",
				),
			),
		).toBe(1);

		await occ("user:delete", "pat-sweep-user");
		expect(
			Number(
				await sql(
					"SELECT count(*) FROM oc_versioniq_pats WHERE owner_uid='pat-sweep-user'",
				),
			),
			"PATs swept on user deletion",
		).toBe(0);
	});
});
