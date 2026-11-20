// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, request as playwrightRequest, test } from "@playwright/test";
import {
	execInInstance,
	occ,
	openSettings,
	openTab,
	runJob,
	sqlExec,
} from "./helpers.ts";

/** The running server's major, from `occ status`. */
async function runningMajor(): Promise<string> {
	const status = JSON.parse(await occ("status", "--output=json"));
	return String(status.versionstring ?? status.version).split(".")[0];
}

/** The running PHP as major.minor, from the instance's own interpreter. */
async function runningPhp(): Promise<string> {
	const { stdout } = await execInInstance([
		"php",
		"-r",
		'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;',
	]);
	return stdout.trim();
}

/**
 * The Upgrade readiness tab: an admin checks every installed app against a
 * target server major and PHP version. The check runs in a queued job, which
 * the spec executes itself instead of waiting for cron.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md
 */
test.describe("upgrade readiness", () => {
	test.beforeEach(async () => {
		// A queued check left by an earlier run would make runJob() ambiguous.
		await sqlExec(
			"DELETE FROM oc_jobs WHERE class LIKE '%UpgradeReadinessJob%'",
		);
	});

	// @e2e upgrade-readiness::an-admin-checks-readiness-for-the-running-major
	test("an admin checks readiness for the running major and sees every installed app in a group", async ({
		page,
	}) => {
		const server = await runningMajor();
		const php = await runningPhp();

		await openSettings(page);
		const panel = await openTab(page, "Upgrade readiness");
		await expect(panel.getByTestId("readiness-server")).toBeVisible();

		await panel.getByTestId("readiness-server").fill(server);
		await panel.getByTestId("readiness-php").fill(php);
		await panel.getByTestId("readiness-check").click();
		await expect(panel.getByTestId("readiness-queued")).toBeVisible();

		await runJob("UpgradeReadinessJob");
		await panel.getByTestId("readiness-reload").click();

		await expect(panel.getByTestId("readiness-checked")).toContainText(
			`Nextcloud ${server}`,
		);
		await expect(panel.getByTestId("readiness-checked")).toContainText(
			`PHP ${php}`,
		);

		// The same targets on the CLI give the list of installed apps the
		// report covers; every one of them must sit in exactly one group.
		const { stdout } = await execInInstance([
			"php",
			"occ",
			"versioniq:readiness",
			`--server=${server}`,
			`--php=${php}`,
			"--json",
		]);
		const expected: string[] = JSON.parse(
			stdout.trim().split("\n").pop() ?? "{}",
		).apps.map((app: { id: string }) => app.id);
		expect(expected.length).toBeGreaterThan(0);

		const groups = panel.getByTestId("readiness-group");
		await expect(groups.first()).toBeVisible();
		for (const id of expected) {
			await expect(
				groups.locator(`[data-testid="readiness-row"][data-app="${id}"]`),
			).toHaveCount(1);
		}
		await expect(panel.getByTestId("readiness-row")).toHaveCount(
			expected.length,
		);
	});

	// @e2e upgrade-readiness::a-non-admin-cannot-read-or-queue-a-check
	test("a non-admin gets 403 on reading and queueing a check", async ({
		baseURL,
	}) => {
		await occ("user:delete", "readiness-e2e-user");
		await execInInstance(
			["php", "occ", "user:add", "--password-from-env", "readiness-e2e-user"],
			{ env: { OC_PASS: "readinessUserPass123" } },
		);
		// Unset explicitly: otherwise the context inherits the project's admin session.
		const context = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: {
				username: "readiness-e2e-user",
				password: "readinessUserPass123",
			},
		});
		try {
			const headers = { "OCS-APIRequest": "true" };
			const read = await context.get(
				"/ocs/v2.php/apps/versioniq/api/readiness?format=json",
				{ headers },
			);
			expect(read.status()).toBe(403);
			const queue = await context.post(
				"/ocs/v2.php/apps/versioniq/api/readiness?format=json",
				{ headers, data: { server: "99", php: "8.4" } },
			);
			expect(queue.status()).toBe(403);
		} finally {
			await context.dispose();
			await occ("user:delete", "readiness-e2e-user");
		}
	});
});
