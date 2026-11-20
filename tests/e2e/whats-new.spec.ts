// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { APIRequestContext } from "@playwright/test";

import { expect, request as playwrightRequest, test } from "@playwright/test";
import {
	dropQueuedJobs,
	execInInstance,
	FIXTURE_APP,
	fixtureAvailable,
	installFixture,
	occ,
	resetFixtureApp,
	runQueuedJobs,
	sql,
} from "./helpers.ts";

/**
 * Users hear about an update made through Versioniq (releases-notes-reach 1.3).
 *
 * An install through Versioniq dispatches the platform's `AppUpdateEvent`
 * (design D1). The platform's updatenotification app answers it by queueing
 * its `AppUpdatedNotifications` job, which notifies every user the app is
 * enabled for. So the spec updates the fixture app 1.0.0 -> 1.0.1 with
 * `occ versioniq:install`, runs that queued job, and reads the user's own
 * notifications through the notifications OCS API, as the user.
 *
 * The user signs in once before the install. The platform notifies only users
 * that have been seen (logged in at least once), so a freshly created account
 * that never made a request would get nothing and the test would blame
 * Versioniq for the platform skipping an unseen user.
 *
 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
 */

const USER = "whatsnew-e2e-user";
const PASS = "whatsNewUserPass123";
const NOTIFY_KEY = "notify_users_on_update";

/** A request context signed in as the test user (never the admin session). */
async function userContext(baseURL: string | undefined): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL,
		// Unset explicitly: otherwise the context inherits the admin session.
		storageState: undefined,
		httpCredentials: { username: USER, password: PASS, send: "always" },
	});
}

/** The user's notifications from the notifications app's OCS API. */
async function userNotifications(
	user: APIRequestContext,
): Promise<Array<{ app: string; object_type: string; object_id: string; subject: string }>> {
	const res = await user.get("/ocs/v2.php/apps/notifications/api/v2/notifications?format=json", {
		headers: { "OCS-APIRequest": "true" },
	});
	expect(res.ok(), `notifications API answered ${res.status()} for ${USER}`).toBe(true);
	return (await res.json())?.ocs?.data ?? [];
}

/** The user's "app updated" notifications about the fixture app. */
async function appUpdatedNotices(user: APIRequestContext) {
	return (await userNotifications(user)).filter(
		(n) => n.app === "updatenotification" && n.object_id === FIXTURE_APP,
	);
}

/** Queued "app updated" jobs of the platform that name the fixture app. */
async function queuedNoticeJobs(): Promise<number> {
	const rows = await sql(
		`SELECT id FROM oc_jobs WHERE class LIKE 'OCA\\\\UpdateNotification\\\\%AppUpdatedNotifications' AND argument LIKE '%${FIXTURE_APP}%'`,
	);
	return rows.split("\n").filter((line) => line.trim() !== "").length;
}

test.describe("users hear about updates made through Versioniq", () => {
	test.beforeEach(async ({ page, baseURL }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		test.setTimeout(180_000);
		await occ("config:app:delete", "versioniq", NOTIFY_KEY);
		await resetFixtureApp(page);
		// The reset itself may have queued an "app updated" notice; drop it so
		// only the install under test can produce one.
		await dropQueuedJobs("UpdateNotification", "AppUpdatedNotifications");

		await occ("user:delete", USER);
		await execInInstance(["php", "occ", "user:add", "--password-from-env", USER], {
			env: { OC_PASS: PASS },
		});
		// Be seen once, see the docblock: an unseen user is never notified.
		const user = await userContext(baseURL);
		try {
			expect(await appUpdatedNotices(user)).toHaveLength(0);
		} finally {
			await user.dispose();
		}
	});

	test.afterEach(async () => {
		await occ("config:app:delete", "versioniq", NOTIFY_KEY);
		await dropQueuedJobs("UpdateNotification", "AppUpdatedNotifications");
		await occ("user:delete", USER);
	});

	// @e2e changelog-visibility::a-user-is-told-about-an-update-the-admin-made-in-versioniq
	test("a user of the app gets the platform's app updated notification", async ({
		page,
		baseURL,
	}) => {
		const { status, body } = await installFixture(page, "1.0.1");
		expect(status, JSON.stringify(body)).toBe(0);
		expect(body.installedVersion).toBe("1.0.1");

		// The event was dispatched: the platform queued its notice job.
		expect(await queuedNoticeJobs(), "no AppUpdateEvent reached updatenotification").toBeGreaterThan(0);
		await runQueuedJobs("UpdateNotification", "AppUpdatedNotifications");

		const user = await userContext(baseURL);
		try {
			const notices = await appUpdatedNotices(user);
			expect(
				notices,
				`${USER} notifications: ${JSON.stringify(await userNotifications(user))}`,
			).toHaveLength(1);
		} finally {
			await user.dispose();
		}
	});

	// @e2e changelog-visibility::users-hear-what-changed-after-an-update-through-versioniq
	test("with the switch off an update notifies no user", async ({
		page,
		baseURL,
	}) => {
		await occ("config:app:set", "versioniq", NOTIFY_KEY, "--value=false", "--type=boolean");

		const { status, body } = await installFixture(page, "1.0.1");
		expect(status, JSON.stringify(body)).toBe(0);
		expect(body.installedVersion).toBe("1.0.1");

		expect(await queuedNoticeJobs()).toBe(0);
		await runQueuedJobs("UpdateNotification", "AppUpdatedNotifications");

		const user = await userContext(baseURL);
		try {
			expect(await appUpdatedNotices(user)).toHaveLength(0);
		} finally {
			await user.dispose();
		}
	});
});
