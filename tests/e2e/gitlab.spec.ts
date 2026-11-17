// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import {
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	occ,
	resetFixtureApp,
} from "./helpers.ts";

const GITLAB_SOURCE = "gitlab:fixturegroup/sub/fixtureapp";

async function occRun(
	...args: string[]
): Promise<{ code: number; stdout: string }> {
	const { code, stdout } = await execInInstance(["php", "occ", ...args]);
	return { code, stdout };
}

function lastJson(out: string): any {
	const m = out.match(/\{[\s\S]*\}\s*$/);
	return m ? JSON.parse(m[0]) : {};
}

async function appConfig(key: string): Promise<string> {
	return (await occ("config:app:get", "versioniq", key)).trim();
}

/**
 * A GitLab project in a subgroup as a release source, served by the fixture
 * forge under `/api/v4`: bind it, list its versions and install one.
 *
 * @spec openspec/changes/sources-gitlab-forge/specs/external-sources/spec.md
 */
test.describe("GitLab release source", () => {
	let trustedBefore = "";

	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		await resetFixtureApp(page);

		// The fixture's GitLab API lives next to its Forgejo one, so derive it
		// from what the bootstrap configured for this instance.
		const forgejoApi = await appConfig("forge.forgejo.api_base");
		const forgejoWeb = await appConfig("forge.forgejo.web_base");
		expect(forgejoApi, "bootstrap set the forgejo api base").toMatch(
			/\/api\/v1$/,
		);
		await occ(
			"config:app:set",
			"versioniq",
			"forge.gitlab.api_base",
			"--value",
			forgejoApi.replace(/\/api\/v1$/, "/api/v4"),
		);
		await occ(
			"config:app:set",
			"versioniq",
			"forge.gitlab.web_base",
			"--value",
			forgejoWeb,
		);

		trustedBefore = await appConfig("trusted_sources");
		const trusted = JSON.parse(trustedBefore || "[]") as string[];
		await occ(
			"config:app:set",
			"versioniq",
			"trusted_sources",
			"--value",
			JSON.stringify([...trusted, "gitlab:fixturegroup/*"]),
		);
	});

	test.afterEach(async ({ page }) => {
		await occ("config:app:delete", "versioniq", "forge.gitlab.api_base");
		await occ("config:app:delete", "versioniq", "forge.gitlab.web_base");
		if (trustedBefore !== "") {
			await occ(
				"config:app:set",
				"versioniq",
				"trusted_sources",
				"--value",
				trustedBefore,
			);
		}
		await resetFixtureApp(page);
	});

	test("binds a GitLab project in a subgroup, lists its versions and installs one", async () => {
		const listed = await occRun(
			"versioniq:versions",
			FIXTURE_APP,
			`--source=${GITLAB_SOURCE}`,
			"--json",
		);
		expect(listed.code, listed.stdout).toBe(0);
		const versions = lastJson(listed.stdout).availableVersions.map(
			(v: { version: string }) => v.version,
		);
		expect(versions).toEqual(
			expect.arrayContaining(["1.2.0", "1.1.0", "1.0.1"]),
		);

		const installed = await occRun(
			"versioniq:install",
			FIXTURE_APP,
			"1.1.0",
			`--source=${GITLAB_SOURCE}`,
			"--json",
		);
		expect(installed.code, installed.stdout).toBe(0);
		expect(lastJson(installed.stdout).installedVersion).toBe("1.1.0");
		expect(
			(await occ("config:app:get", FIXTURE_APP, "installed_version")).trim(),
		).toBe("1.1.0");

		const binding = JSON.parse(await appConfig(`source.${FIXTURE_APP}`));
		expect(binding.forge).toBe("gitlab");
		expect(binding.path).toBe("fixturegroup/sub/fixtureapp");
	});
});
