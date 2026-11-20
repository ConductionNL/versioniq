// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import type { APIRequestContext, Page } from "@playwright/test";

import { expect, request as playwrightRequest, test } from "@playwright/test";
import { NC_ADMIN_PASS, NC_ADMIN_USER } from "../../playwright.config";
import {
	appConfigValue,
	awaitWebInstanceSettled,
	FIXTURE_APP,
	FIXTURE_URL,
	fixtureAvailable,
	occ,
	openSettings,
	openTab,
	resetFixtureApp,
} from "./helpers.ts";

/**
 * Install from a file (install-manual-upload 3.3), with a fixture archive.
 *
 * The archive is the fixture forge's genuine `fixtureapp-1.0.1.tar.gz`, fetched
 * from the fixture rather than read from disk so the spec does not depend on
 * where the artifacts were built. `fixtureapp` is not an App Store app, so the
 * upload is unsigned: refused while `install.allow_unsigned_upload` is off, and
 * installed with the expected SHA-256 once it is on.
 *
 * Both upload routes require STRICT password confirmation, which Nextcloud
 * checks against the Basic credentials of the request itself, so they are
 * called from a context that sends the admin's credentials on every request
 * rather than from the stored browser session.
 *
 * The install is the HTTP route (there is no occ twin for an upload), so it is
 * followed by `awaitWebInstanceSettled`, as every occ install in these specs is.
 *
 * @spec openspec/changes/install-manual-upload/specs/external-sources/spec.md
 */

const ARCHIVE = `${FIXTURE_APP}-1.0.1.tar.gz`;
const OCS = { "OCS-APIRequest": "true" };
const UNSIGNED_KEY = "install.allow_unsigned_upload";

/** A request context that carries the admin's password on every call. */
async function confirmedAdmin(baseURL: string | undefined): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL,
		storageState: undefined,
		httpCredentials: {
			username: NC_ADMIN_USER,
			password: NC_ADMIN_PASS,
			// Nextcloud answers an unauthenticated OCS call with a 401 that
			// carries no Basic challenge, so the credentials must go up front.
			send: "always",
		},
	});
}

/** The fixture archive's bytes and the SHA-256 its sibling publishes. */
async function fixtureArchive(page: Page): Promise<{ bytes: Buffer; sha256: string }> {
	const archive = await page.request.get(`${FIXTURE_URL}/dl/${ARCHIVE}`);
	expect(archive.ok(), `the fixture did not serve ${ARCHIVE}`).toBe(true);
	const sibling = await page.request.get(`${FIXTURE_URL}/dl/${ARCHIVE}.sha256`);
	expect(sibling.ok(), `the fixture did not serve ${ARCHIVE}.sha256`).toBe(true);
	return {
		bytes: await archive.body(),
		sha256: (await sibling.text()).trim().split(/\s+/)[0],
	};
}

/** Uploads the archive to the inspect route and returns what it read. */
async function inspect(
	admin: APIRequestContext,
	bytes: Buffer,
): Promise<{ token: string; appId: string; version: string; sha256: string; signedByStore: boolean }> {
	const res = await admin.post("/ocs/v2.php/apps/versioniq/api/upload/inspect?format=json", {
		headers: OCS,
		multipart: {
			archive: { name: ARCHIVE, mimeType: "application/gzip", buffer: bytes },
		},
	});
	const body = await res.json();
	expect(res.status(), JSON.stringify(body)).toBe(200);
	return body.ocs.data;
}

/** Installs an inspected upload by its token. */
async function installUpload(
	admin: APIRequestContext,
	token: string,
	expectedSha256: string,
): Promise<{ status: number; data: any }> {
	const res = await admin.post(
		`/ocs/v2.php/apps/versioniq/api/upload/${encodeURIComponent(token)}/install?format=json`,
		{ headers: OCS, data: { expectedSha256 } },
	);
	return { status: res.status(), data: (await res.json())?.ocs?.data ?? {} };
}

/** The version the instance records for the fixture app. */
async function installedVersion(): Promise<string> {
	return (await occ("config:app:get", FIXTURE_APP, "installed_version")).trim();
}

test.describe("install from an uploaded archive", () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), "forge fixture not running");
		test.setTimeout(180_000);
		await resetFixtureApp(page);
		await occ("config:app:delete", "versioniq", UNSIGNED_KEY);
	});

	test.afterEach(async () => {
		await occ("config:app:delete", "versioniq", UNSIGNED_KEY);
	});

	// @e2e external-sources::an-unsigned-upload-is-refused-by-default
	test("an unsigned upload is refused while unsigned uploads are off", async ({
		page,
		baseURL,
	}) => {
		const { bytes, sha256 } = await fixtureArchive(page);
		const admin = await confirmedAdmin(baseURL);
		try {
			const inspected = await inspect(admin, bytes);
			expect(inspected.signedByStore).toBe(false);

			const { status, data } = await installUpload(admin, inspected.token, sha256);
			expect(status, JSON.stringify(data)).toBe(403);
			expect(data.category).toBe("unsigned_upload");
			expect(data.message).toContain("Allow unsigned uploads");
		} finally {
			await admin.dispose();
		}

		expect(await installedVersion()).toBe("1.0.0");
	});

	// @e2e external-sources::an-admin-installs-a-supplier-build
	test("an unsigned upload installs with the expected SHA-256 and is audited as upload", async ({
		page,
		baseURL,
	}) => {
		await occ("config:app:set", "versioniq", UNSIGNED_KEY, "--value=true", "--type=boolean");
		const bindingBefore = await appConfigValue(page, `source.${FIXTURE_APP}`);
		const { bytes, sha256 } = await fixtureArchive(page);

		const admin = await confirmedAdmin(baseURL);
		try {
			// What the admin sees before anything is installed.
			const inspected = await inspect(admin, bytes);
			expect(inspected.appId).toBe(FIXTURE_APP);
			expect(inspected.version).toBe("1.0.1");
			expect(inspected.sha256).toBe(sha256);
			expect(inspected.signedByStore).toBe(false);
			expect(await installedVersion()).toBe("1.0.0");

			const { status, data } = await installUpload(admin, inspected.token, sha256);
			expect(status, JSON.stringify(data)).toBe(200);
			expect(data.installStatus).toBe("installed");
			expect(data.installedVersion).toBe("1.0.1");
			expect(data.sourceId).toBe("upload");
			expect(data.sha256).toBe(sha256);
		} finally {
			await admin.dispose();
		}
		await awaitWebInstanceSettled(page);

		expect(await installedVersion()).toBe("1.0.1");
		// The upload is not the bound source: the next version list still comes
		// from the forge.
		expect(await appConfigValue(page, `source.${FIXTURE_APP}`)).toBe(bindingBefore);

		await openSettings(page);
		const history = await openTab(page, "History");
		const row = history
			.locator(
				`[data-testid="history-row"][data-operation="install"][data-app-id="${FIXTURE_APP}"]`,
			)
			.first();
		await expect(row).toBeVisible();
		await expect(row).toContainText("1.0.1");
		await expect(row).toContainText("upload");
		await expect(row).toContainText(sha256);
	});
});
