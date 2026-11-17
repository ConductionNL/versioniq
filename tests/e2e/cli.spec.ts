// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import {
	execInInstance,
	FIXTURE_APP,
	FIXTURE_SOURCE,
	fixtureAvailable,
	fixtureControl,
	resetFixtureApp,
} from "./helpers.ts";

/**
 * Runs an occ command, returning its exit code and stdout (never throws).
 *
 * This spec asserts on the EXIT CODE — a refused downgrade and an integrity
 * failure are supposed to be non-zero — so it keeps its own thin wrapper rather
 * than using `occ()`, which returns stdout alone.
 * @param {...any} args
 */
async function occ(
	...args: string[]
): Promise<{ code: number; stdout: string }> {
	const { code, stdout } = await execInInstance(["php", "occ", ...args]);
	return { code, stdout };
}

function lastJson(out: string): any {
	const m = out.match(/\{[\s\S]*\}\s*$/);
	return m ? JSON.parse(m[0]) : {};
}

/**
 * The occ CLI surface: `versioniq:versions` and `versioniq:install`.
 * Drives the real commands against the fixture forge.
 *
 * @spec openspec/specs/cli-commands/spec.md
 */
test.describe("occ CLI", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);
	});

	test("lists versions in human-readable form", async () => {
		const { code, stdout } = await occ(
			"versioniq:versions",
			FIXTURE_APP,
			`--source=${FIXTURE_SOURCE}`,
		);
		expect(code).toBe(0);
		expect(stdout).toMatch(/1\.1\.0/);
		expect(stdout).toMatch(/installed/i);
	});

	test("lists versions as JSON", async () => {
		const { code, stdout } = await occ(
			"versioniq:versions",
			FIXTURE_APP,
			`--source=${FIXTURE_SOURCE}`,
			"--json",
		);
		expect(code).toBe(0);
		const data = lastJson(stdout);
		expect(data.installedVersion).toBeTruthy();
		expect(data.availableVersions.map((v: any) => v.version)).toContain(
			"1.1.0",
		);
	});

	test("exits non-zero for an unknown app", async () => {
		const { code } = await occ("versioniq:versions", "no-such-app-xyz");
		expect(code).not.toBe(0);
	});

	test("installs a specific version reproducibly", async () => {
		const { code, stdout } = await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.0.1",
			`--source=${FIXTURE_SOURCE}`,
			"--json",
		);
		expect(code).toBe(0);
		expect(lastJson(stdout).installedVersion).toBe("1.0.1");
	});

	test("refuses a downgrade without --allow-downgrade, then proceeds with it", async () => {
		await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.1.0",
			`--source=${FIXTURE_SOURCE}`,
			"--json",
		);
		const refused = await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.0.0",
			`--source=${FIXTURE_SOURCE}`,
			"--json",
		);
		expect(refused.code).toBe(3); // documented downgrade-refused exit code
		expect(lastJson(refused.stdout).category).toBe("downgrade_guard");

		const ok = await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.0.0",
			`--source=${FIXTURE_SOURCE}`,
			"--allow-downgrade",
			"--json",
		);
		expect(ok.code).toBe(0);
		expect(lastJson(ok.stdout).installedVersion).toBe("1.0.0");
	});

	test("a dry run leaves the instance untouched", async () => {
		const before = (
			await occ("config:app:get", FIXTURE_APP, "installed_version")
		).stdout.trim();
		const { stdout } = await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.1.0",
			`--source=${FIXTURE_SOURCE}`,
			"--dry-run",
			"--json",
		);
		expect(lastJson(stdout).dryRun).toBe(true);
		const after = (
			await occ("config:app:get", FIXTURE_APP, "installed_version")
		).stdout.trim();
		expect(after).toBe(before);
	});

	test("an integrity failure exits with the documented distinct code", async ({
		page,
	}) => {
		// Record the genuine digest, move off it, rewrite the release, reinstall.
		await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.0.1",
			`--source=${FIXTURE_SOURCE}`,
			"--json",
		);
		await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.0.0",
			`--source=${FIXTURE_SOURCE}`,
			"--allow-downgrade",
			"--json",
		);
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz",
			serveInstead: "fixtureapp-1.0.1-tampered.tar.gz",
		});
		await fixtureControl(page, "asset", {
			asset: "fixtureapp-1.0.1.tar.gz.sha256",
			serveInstead: "fixtureapp-1.0.1-tampered.tar.gz.sha256",
		});
		const { code, stdout } = await occ(
			"versioniq:install",
			FIXTURE_APP,
			"1.0.1",
			`--source=${FIXTURE_SOURCE}`,
			"--json",
		);
		expect(code).toBe(6); // documented integrity exit code
		expect(lastJson(stdout).category).toMatch(/sha_mismatch|checksum/);
	});

	test("refuses to manage Versioniq itself", async () => {
		const { code } = await occ("versioniq:install", "versioniq", "1.0.0");
		expect(code).not.toBe(0);
	});

	// @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md
	test("versioniq:channel puts one app on beta and refuses a typo", async () => {
		try {
			const set = await occ("versioniq:channel", FIXTURE_APP, "beta");
			expect(set.code, set.stdout).toBe(0);
			const listed = await occ(
				"versioniq:versions",
				FIXTURE_APP,
				`--source=${FIXTURE_SOURCE}`,
				"--json",
			);
			expect(lastJson(listed.stdout).channel).toBe("beta");

			const typo = await occ("versioniq:channel", FIXTURE_APP, "betta");
			expect(typo.code).not.toBe(0);
			const after = await occ(
				"versioniq:versions",
				FIXTURE_APP,
				`--source=${FIXTURE_SOURCE}`,
				"--json",
			);
			expect(lastJson(after.stdout).channel).toBe("beta");
		} finally {
			await occ("versioniq:channel", FIXTURE_APP, "default");
		}
	});
	// @spec openspec/changes/autoupdate-proposed-updates/specs/cli-commands/spec.md#requirement-dry-run-the-automatic-update-policy-from-the-cli
	test("versioniq:auto-update --dry-run --json plans 1.0.1 and installs nothing", async ({ page }) => {
		// @e2e cli-commands::dry-run-the-automatic-update-policy
		const put = await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/policy?format=json`,
			{ headers: { "OCS-APIRequest": "true", "Content-Type": "application/json" }, data: { level: "patch" } },
		);
		expect(put.ok(), "setting the policy should succeed").toBeTruthy();
		try {
			await occ("config:app:set", "versioniq", "auto_update_enabled", "--value", "1");
			const run = await occ("versioniq:auto-update", "--dry-run", "--json");
			expect(run.code, run.stdout).toBe(0);
			const plan = lastJson(run.stdout);
			expect(plan.autoUpdateEnabled).toBe(true);
			const row = (plan.apps ?? []).find((app: { appId: string }) => app.appId === FIXTURE_APP);
			expect(row).toMatchObject({ fromVersion: "1.0.0", toVersion: "1.0.1", action: "install" });
			// A dry run only reads: the app stays at 1.0.0.
			expect((await occ("config:app:get", FIXTURE_APP, "installed_version")).stdout.trim()).toBe("1.0.0");

			// With automatic updates off it still prints the plan, and exits 1.
			await occ("config:app:set", "versioniq", "auto_update_enabled", "--value", "0");
			const off = await occ("versioniq:auto-update", "--dry-run", "--json");
			expect(off.code).toBe(1);
			expect(lastJson(off.stdout).autoUpdateEnabled).toBe(false);
		} finally {
			await occ("config:app:delete", "versioniq", "auto_update_enabled");
			await page.request
				.delete(`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/policy?format=json`, {
					headers: { "OCS-APIRequest": "true" },
				})
				.catch(() => undefined);
		}
	});
});
