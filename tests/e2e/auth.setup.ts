import { expect, test as setup } from "@playwright/test";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import {
	AUTH_FILE,
	NC_ADMIN_PASS,
	NC_ADMIN_USER,
} from "../../playwright.config";

const execFileAsync = promisify(execFile);
const NC_CONTAINER = process.env.NC_CONTAINER ?? "av-e2e";

/**
 * Logs in as the admin once and stores the session for every other spec.
 * Nextcloud's login form is server-rendered, so this is a plain form post.
 */
setup("authenticate as admin", async ({ page }) => {
	// This step also warms the App Store caches below, which on a cold instance
	// is a multi-minute download — well past the suite's per-test timeout.
	setup.setTimeout(900_000);

	// The version-listing specs need a genuine, installed App Store app as their
	// subject (see docs/e2e.md). Install `notes` idempotently up front so the
	// suite is self-provisioning rather than relying on a manual setup step; a
	// non-docker or offline environment simply skips this and those specs fail
	// loudly on the missing baseline instead of silently drifting.
	for (const args of [
		["app:install", "notes"],
		["app:enable", "notes"],
	]) {
		await execFileAsync(
			"docker",
			["exec", "-u", "www-data", NC_CONTAINER, "php", "occ", ...args],
			{
				maxBuffer: 8 * 1024 * 1024,
			},
		).catch(() => undefined);
	}

	await page.goto("/login");
	await page.locator("#user").fill(NC_ADMIN_USER);
	await page.locator("#password").fill(NC_ADMIN_PASS);
	await page.locator("button[type=submit]").click();

	// Landing anywhere authenticated is enough; go straight to our settings page
	// so a broken login fails here rather than in every functional spec.
	//
	// `domcontentloaded` for the same reason as helpers.ts::openSettings — the
	// default `load` waits for sub-resources a Nextcloud settings page keeps in
	// flight, so the navigation runs to the timeout and is reported as
	// `net::ERR_ABORTED`. This one is the more dangerous of the two: it runs in
	// SETUP, so a stall here does not fail one spec, it fails the suite.
	await page.goto("/index.php/settings/admin/versioniq", {
		waitUntil: "domcontentloaded",
	});

	// Nextcloud's first-run wizard is a modal that covers the page and swallows
	// clicks and focus. Instances used for e2e should have `firstrunwizard`
	// disabled (see docs/e2e.md), but dismiss it defensively so the suite also
	// works on an instance where it is enabled.
	const wizard = page.locator("#firstrunwizard");
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press("Escape");
		await expect(wizard).toBeHidden();
	}

	await expect(
		page.getByRole("heading", { name: "Versioniq", level: 2 }),
	).toBeVisible();

	await page.context().storageState({ path: AUTH_FILE });

	// Warm the App Store caches once.
	//
	// The catalogue endpoint answers with the whole store (~30 MB) and its
	// results are cached for an hour; a spec that happens to run just after the
	// cache expires would otherwise wait out a full cold download and look like
	// a product failure. Paying it here, deliberately and visibly, keeps the
	// functional specs fast and deterministic. Failures are ignored: an offline
	// instance should surface in the specs that actually assert on the data.
	for (const url of [
		"/ocs/v2.php/apps/versioniq/api/app/notes/versions?format=json",
		"/ocs/v2.php/apps/versioniq/api/discover?q=calendar&format=json",
	]) {
		// 45s, not 240s: the setup project inherits the 60s test bound, so a
		// longer request timeout could never elapse — it would abort the whole
		// setup with a timeout naming nothing. The warm-up now hits the local
		// fixture catalogue rather than the ~12.4 MB store, so this is generous.
		await page.request
			.get(url, {
				headers: { "OCS-APIRequest": "true" },
				timeout: 45_000,
			})
			.catch(() => undefined);
	}
});
