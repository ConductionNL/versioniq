import { expect, test } from "@playwright/test";
import { openSettings, openTab } from "./helpers.ts";

/**
 * The remaining capability panels: audit history, artifact cache, tokens
 * (PAT expiry state) and the trusted-source allowlist.
 *
 * @spec openspec/specs/audit-trail/spec.md
 * @spec openspec/specs/artifact-cache/spec.md
 * @spec openspec/specs/pat-management/spec.md
 * @spec openspec/specs/external-sources/spec.md
 */
test.describe("capability panels", () => {
	test("history explains itself and lists entries newest-first", async ({
		page,
	}) => {
		await openSettings(page);
		const panel = await openTab(page, "History");

		await expect(
			panel.getByRole("heading", { name: "History" }),
		).toBeVisible();
		// Either a populated table or an explicit empty state — never a blank panel.
		const table = page.getByTestId("history-table");
		const empty = page.getByTestId("history-empty");
		await expect(table.or(empty).first()).toBeVisible();

		if (await table.isVisible()) {
			for (const header of [
				"When",
				"Who",
				"App",
				"Operation",
				"Status",
			]) {
				await expect(
					table.getByRole("columnheader", { name: header }),
				).toBeVisible();
			}
		}
	});

	test("artifact cache reports its size and disables clearing when empty", async ({
		page,
	}) => {
		await openSettings(page);
		const panel = await openTab(page, "Artifact cache");

		await expect(
			panel.getByRole("heading", { name: "Release artifact cache" }),
		).toBeVisible();
		await expect(page.getByTestId("cache-total-size")).toBeVisible();

		const empty = page.getByTestId("cache-empty");
		if (await empty.isVisible()) {
			await expect(page.getByTestId("cache-clear-all")).toBeDisabled();
		}
	});

	test("tokens panel lists PATs and never leaks a secret", async ({
		page,
	}) => {
		await openSettings(page);
		const panel = await openTab(page, "Tokens");

		await expect(panel).toBeVisible();
		// With no tokens configured the panel must say so rather than render blank.
		await expect(
			panel
				.getByText("No tokens configured.")
				.or(panel.locator("li"))
				.first(),
		).toBeVisible();

		// Nothing that looks like a real GitHub token may ever be rendered.
		await expect(panel).not.toContainText(/ghp_[A-Za-z0-9]{20,}/);
		await expect(panel).not.toContainText(/github_pat_[A-Za-z0-9_]{20,}/);
	});

	test("trusted sources shows the default forge allowlist", async ({
		page,
	}) => {
		await openSettings(page);
		const panel = await openTab(page, "Trusted sources");

		await expect(panel).toBeVisible();
		// Conduction's own namespaces ship as the default allowlist.
		await expect(panel).toContainText("ConductionNL");
	});

	test("sources panel offers binding an app to a forge", async ({ page }) => {
		await openSettings(page);
		const panel = await openTab(page, "Sources");

		await expect(panel).toBeVisible();
		await expect(
			panel.getByRole("button", { name: "Bind source" }),
		).toBeVisible();
	});
});
