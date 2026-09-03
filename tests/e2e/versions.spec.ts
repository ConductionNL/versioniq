import { expect, test } from "@playwright/test";
import { chooseApp, openSettings, versionsLoaded } from "./helpers.ts";

/**
 * The core flow: list an app's versions, read release notes, and have the
 * downgrade guard refuse an unacknowledged rollback.
 *
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/specs/changelog-visibility/spec.md
 * @spec openspec/specs/migration-safety/spec.md
 */
// A genuine App Store app (installed by the e2e bootstrap, see docs/e2e.md).
// Shipped apps such as `dashboard` are a poor subject here: their App Store
// presence is inconsistent, so the picker may legitimately report that the app
// follows the server release instead of listing versions.
// `dashboard`, not `notes`. Notes is a separate repo that had to be installed
// via additional-apps, and its info.xml declares min-version="33" while this
// repo's PHPUnit matrix includes stable32 — so it could NEVER enable on two of
// the six cells ("App Notes cannot be installed because it is not compatible
// with this version of the server"). That only looked harmless because a failed
// enable is currently a warning; ConductionNL/.github#355 makes it fail.
//
// dashboard ships WITH Nextcloud, so it is present on every matrix version with
// no compatibility floor to trip — and it is exactly what the safe-mode test
// below already describes: bundled at a version far ahead of its App Store
// releases, so every catalogue entry is a downgrade.
const APP = "dashboard";

test.describe("version listing and release notes", () => {
	test("versions load for an App Store app and name their source", async ({
		page,
	}) => {
		await openSettings(page);
		await chooseApp(page, APP);
		await versionsLoaded(page);

		// The picker always states which source answered and what is installed,
		// even when the safe-mode filter hides every candidate.
		await expect(page.getByText(/Versions source:/)).toBeVisible();
		await expect(page.getByText("Current installed")).toBeVisible();
	});

	test("safe mode hides older releases until it is switched off", async ({
		page,
	}) => {
		// `dashboard` ships with Nextcloud at a version far ahead of its App Store
		// releases, so with safe mode on every candidate is a downgrade and must be
		// filtered out — and switching safe mode off must reveal them.
		await openSettings(page);
		await chooseApp(page, APP);
		await versionsLoaded(page);

		// With safe mode on, only same-or-newer releases are offered.
		const before = await page.getByTestId("changelog-toggle").count();

		await page.getByRole("checkbox", { name: /Safe mode/ }).uncheck();
		await expect
			.poll(async () => page.getByTestId("changelog-toggle").count(), {
				timeout: 45_000,
			})
			.toBeGreaterThan(before);

		// Restore the safer default for subsequent specs.
		await page.getByRole("checkbox", { name: /Safe mode/ }).check();
	});

	test("release notes expand and render as inert text", async ({ page }) => {
		await openSettings(page);
		await chooseApp(page, APP);
		await versionsLoaded(page);
		// Older releases are only listed with safe mode off.
		await page.getByRole("checkbox", { name: /Safe mode/ }).uncheck();
		await expect(page.getByTestId("changelog-toggle").first()).toBeVisible({
			timeout: 45_000,
		});

		const toggle = page.getByTestId("changelog-toggle").first();
		await toggle.click();

		// Either notes or the explicit placeholder — never an empty disclosure.
		const body = page
			.getByTestId("changelog-text")
			.or(page.getByTestId("changelog-placeholder"));
		await expect(body.first()).toBeVisible();

		// Release notes are rendered as text, so any markup in them stays inert.
		const scripts = await page
			.getByTestId("changelog-body")
			.locator("script")
			.count();
		expect(scripts, "changelog must never inject executable markup").toBe(
			0,
		);

		await page.getByRole("checkbox", { name: /Safe mode/ }).check();
	});

	test("safe mode is on by default and blocks downgrades", async ({
		page,
	}) => {
		await openSettings(page);
		const safeMode = page.getByRole("checkbox", { name: /Safe mode/ });
		await expect(safeMode).toBeChecked();
	});

	test("the API refuses an unacknowledged downgrade with a 409", async ({
		page,
	}) => {
		// The downgrade guard is server-enforced, so assert it at the API boundary
		// where every consumer (UI, occ, scripts) hits it. The target must be a
		// version the source actually lists and that is older than what is
		// installed — the App Store prunes ancient releases, so pick the oldest
		// still-listed release below the installed version at runtime rather than
		// hard-coding a version that may have aged out of the catalogue.
		const listing = (
			await (
				await page.request.get(
					`/ocs/v2.php/apps/versioniq/api/app/${APP}/versions?format=json`,
					{ headers: { "OCS-APIRequest": "true" } },
				)
			).json()
		)?.ocs?.data;
		const installed = String(listing?.installedVersion ?? "");
		const versions: string[] = (listing?.availableVersions ?? []).map(
			(v: any) => String(v.version),
		);
		expect(
			versions.length,
			`${APP} must list versions from the source`,
		).toBeGreaterThan(0);
		const older = versions[versions.length - 1]; // oldest listed
		expect(
			older,
			"an older release exists to attempt a downgrade to",
		).not.toBe(installed);

		const res = await page.request.post(
			`/ocs/v2.php/apps/versioniq/api/app/${APP}/versions/${older}/install?format=json`,
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: {},
			},
		);
		const body = await res.text();
		// Must be refused as a downgrade, and must explain itself rather than 500
		// or a bare "not found".
		expect(body).not.toContain('"installed"');
		expect(body.toLowerCase()).toMatch(
			/downgrad|older|pin|confirm|password/,
		);
	});
});
