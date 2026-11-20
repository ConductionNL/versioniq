// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { Page } from "@playwright/test";

import { expect, request as playwrightRequest, test } from "@playwright/test";
import {
	appConfigValue,
	dropQueuedJobs,
	FIXTURE_APP,
	FIXTURE_SOURCE,
	FIXTURE_URL,
	fixtureAvailable,
	fixtureControl,
	occ,
	resetFixture,
	resetFixtureApp,
	runJob,
	runQueuedJobs,
	sqlExec,
} from "./helpers.ts";

/**
 * New-release alerts (releases-new-release-alerts 4.3), driven against the
 * fixture forge.
 *
 * The fixture app is bound to the forge, so it is inside the default `forge`
 * scope. A first availability sweep sets the baseline at the forge's newest
 * release (1.2.0); the forge then publishes 1.3.0 and the next sweep, run as
 * the background job with `occ background-job:execute --force-execute`, must
 * log it, notify the admin, list it in the RSS feed (design D4) and queue a
 * delivery to the webhook target.
 *
 * The webhook target is stored with `occ config:app:set versioniq
 * webhook.fixture`, because the form accepts https only and the fixture
 * listens on http — the same way bootstrap.sh points the forge addresses at
 * it. It posts to the fixture's `/hooks/fixture` route, which records the
 * delivery for the test to read back through `/control/webhooks`.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md
 */

/** The RSS feed of the release log (design D4: a FrontpageRoute, not OCS). */
const FEED_URL = "/index.php/apps/versioniq/feed/releases.rss";

/** The webhook target id; its config key is `webhook.<id>`. */
const HOOK_ID = "fixture";

const OCS = { "OCS-APIRequest": "true" };

/** The forge's default release set with a newer 1.3.0 on top. */
const WITH_NEWER_RELEASE = [
	{ tag: "v1.3.0", asset: "fixtureapp-1.3.0.tar.gz", sha: true },
	{ tag: "v1.2.0", asset: "fixtureapp-1.2.0.tar.gz", sha: true },
	{ tag: "v1.1.0", asset: "fixtureapp-1.1.0.tar.gz", sha: true },
	{ tag: "v1.0.1", asset: "fixtureapp-1.0.1.tar.gz", sha: true },
	{ tag: "v1.0.0", asset: "fixtureapp-1.0.0.tar.gz", sha: true },
	{ tag: "v0.9.0", asset: "fixtureapp-0.9.0.tar.gz", sha: true },
];

type Delivery = {
	name: string;
	headers: Record<string, string>;
	body: {
		app?: string;
		kind?: string;
		subject?: string;
		parameters?: Record<string, string>;
		text?: string;
	} | null;
};

/** The deliveries the fixture recorded on `/hooks/<HOOK_ID>`. */
async function deliveries(page: Page): Promise<Delivery[]> {
	const res = await page.request.post(`${FIXTURE_URL}/control/webhooks`, {
		data: { name: HOOK_ID },
	});
	expect(res.ok(), "the fixture's webhook log could not be read").toBe(true);
	return (await res.json()).deliveries ?? [];
}

/**
 * The fixture's address as the INSTANCE reaches it. That is not FIXTURE_URL
 * (the host's view): bootstrap stored it as the forge's api_base, so the
 * webhook follows whatever address the forge calls already work on.
 */
async function fixtureBaseFromInstance(page: Page): Promise<string> {
	const apiBase = await appConfigValue(page, "forge.forgejo.api_base");
	expect(apiBase, "forge.forgejo.api_base is not set; run bootstrap.sh").toBeTruthy();
	return (apiBase as string).replace(/\/api\/v1\/?$/, "");
}

/** The admin's notifications, as the notifications app serves them. */
async function adminNotifications(
	page: Page,
): Promise<Array<{ app: string; subject: string; object_id: string }>> {
	const res = await page.request.get(
		"/ocs/v2.php/apps/notifications/api/v2/notifications?format=json",
		{ headers: OCS },
	);
	expect(res.ok(), `notifications API answered ${res.status()}`).toBe(true);
	return (await res.json())?.ocs?.data ?? [];
}

/** Forgets every alert, target and delivery an earlier test left behind. */
async function clearAlertState(page: Page): Promise<void> {
	await occ("config:app:delete", "versioniq", `webhook.${HOOK_ID}`);
	await occ("config:app:delete", "versioniq", `webhook.${HOOK_ID}.last`);
	await occ("config:app:delete", "versioniq", "release_alerts.log");
	await occ("config:app:delete", "versioniq", "release_alerts.scope");
	await dropQueuedJobs("Versioniq", "WebhookDeliveryJob");
	await sqlExec(
		"DELETE FROM oc_notifications WHERE app='versioniq' AND subject='new_release'",
	);
	await page.request
		.post(`${FIXTURE_URL}/control/webhooks`, { data: { clear: true } })
		.catch(() => undefined);
}

test.describe("new release alerts", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		test.setTimeout(180_000);
		await resetFixtureApp(page);
		await clearAlertState(page);
		// The baseline: this sweep records 1.2.0 as the newest version. Whatever
		// it announces against an older snapshot is cleared right after, so the
		// test only sees what the 1.3.0 sweep raises.
		await runJob("AvailabilityRefreshJob");
		await clearAlertState(page);
	});

	test.afterEach(async ({ page }) => {
		await clearAlertState(page);
		await resetFixture(page);
	});

	// @e2e release-alerts::a-forge-release-is-logged
	// @e2e release-alerts::a-forge-release-reaches-the-bell
	// @e2e release-alerts::a-feed-reader-signs-in-with-an-app-password
	test("a newer forge release is logged, reaches the admin's bell and the feed", async ({
		page,
	}) => {
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			releases: WITH_NEWER_RELEASE,
		});

		await runJob("AvailabilityRefreshJob");

		const log = JSON.parse((await appConfigValue(page, "release_alerts.log")) ?? "[]");
		const entry = log.find(
			(e: { appId: string; version: string }) =>
				e.appId === FIXTURE_APP && e.version === "1.3.0",
		);
		expect(entry, `release log: ${JSON.stringify(log)}`).toBeTruthy();
		expect(entry.sourceId).toBe(FIXTURE_SOURCE);
		expect(entry.detectedAt).toBeTruthy();

		const notices = await adminNotifications(page);
		expect(
			notices.filter((n) => n.app === "versioniq").map((n) => n.subject),
		).toContain(`New version of ${FIXTURE_APP}: 1.3.0`);

		const feed = await page.request.get(FEED_URL);
		expect(feed.status()).toBe(200);
		expect(feed.headers()["content-type"]).toContain("application/rss+xml");
		const xml = await feed.text();
		expect(xml).toContain("<rss");
		expect(xml).toContain(`<title>${FIXTURE_APP} 1.3.0</title>`);
	});

	// @e2e release-alerts::the-feed-is-not-public
	test("the feed answers no app or version without credentials", async ({
		page,
		baseURL,
	}) => {
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			releases: WITH_NEWER_RELEASE,
		});
		await runJob("AvailabilityRefreshJob");

		// Unset explicitly: otherwise the context inherits the admin session.
		const anonymous = await playwrightRequest.newContext({
			baseURL,
			storageState: undefined,
		});
		try {
			const res = await anonymous.get(FEED_URL, { maxRedirects: 0 });
			expect(res.status()).not.toBe(200);
			const body = await res.text();
			expect(body).not.toContain(FIXTURE_APP);
			expect(body).not.toContain("1.3.0");
		} finally {
			await anonymous.dispose();
		}
	});

	// @e2e alert-webhooks::every-versioniq-alert-is-delivered-to-matching-targets-in-the-background
	test("a webhook target receives the new_release delivery", async ({
		page,
	}) => {
		const base = await fixtureBaseFromInstance(page);
		await occ(
			"config:app:set",
			"versioniq",
			`webhook.${HOOK_ID}`,
			"--value",
			JSON.stringify({
				name: "Fixture receiver",
				url: `${base}/hooks/${HOOK_ID}`,
				format: "json",
				kinds: ["new_release"],
				enabled: true,
			}),
		);
		await fixtureControl(page, "repo", {
			repo: "fixtureowner/fixtureapp",
			releases: WITH_NEWER_RELEASE,
		});

		await runJob("AvailabilityRefreshJob");

		// The sweep only QUEUES the delivery (design D6); the queued job sends
		// it. Its count is not asserted: an ajax/webcron tick on the instance may
		// legitimately have run it already, and the recorded delivery is the proof.
		await runQueuedJobs("Versioniq", "WebhookDeliveryJob");

		const received = (await deliveries(page)).find(
			(d) =>
				d.body?.subject === "new_release" &&
				d.body?.parameters?.app === FIXTURE_APP,
		);
		expect(
			received,
			`deliveries: ${JSON.stringify(await deliveries(page))}`,
		).toBeTruthy();
		expect(received?.body?.app).toBe("versioniq");
		expect(received?.body?.kind).toBe("new_release");
		expect(received?.body?.parameters?.version).toBe("1.3.0");
		expect(received?.body?.text).toContain("1.3.0");

		const last = JSON.parse(
			(await appConfigValue(page, `webhook.${HOOK_ID}.last`)) ?? "{}",
		);
		expect(last.status).toBe("succeeded");
	});
});
