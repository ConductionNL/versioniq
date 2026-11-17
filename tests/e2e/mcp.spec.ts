// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { expect, test } from "@playwright/test";
import { NC_ADMIN_USER } from "../../playwright.config";
import { execInInstance, occ } from "./helpers.ts";

/**
 * OpenRegister's MCP discovery finds Versioniq's tool provider
 * (admin-mcp-assistant 2.1), on an instance where OpenRegister is installed,
 * as it is in CI.
 *
 * The check runs inside the instance: it boots Nextcloud, resolves
 * OpenRegister's McpToolsService from the container, and lists the tools as
 * an admin and as nobody. Versioniq's five tools are read-only and for admins
 * only, so the second list must hold none of them.
 *
 * @spec openspec/changes/admin-mcp-assistant/specs/assistant-tools/spec.md
 */
const EXPECTED = [
	"versioniq.explainAdvisory",
	"versioniq.listAdvisories",
	"versioniq.listApps",
	"versioniq.listPendingUpdates",
	"versioniq.listVersions",
];

/** The versioniq tool ids McpToolsService lists for a user (none for an empty uid), and whether our provider was discovered. */
async function discovered(uid: string): Promise<{ ids: string[]; provider: number; readOnly: boolean }> {
	const script = [
		"require 'lib/base.php';",
		"$c = \\OC::$server;",
		`$uid = ${JSON.stringify(uid)};`,
		"if ($uid !== '') { $c->get(\\OCP\\IUserSession::class)->setUser($c->get(\\OCP\\IUserManager::class)->get($uid)); }",
		"$svc = $c->get(\\OCA\\OpenRegister\\Mcp\\Service\\Mcp\\McpToolsService::class);",
		"$tools = array_values(array_filter($svc->listTools()['tools'], fn ($t) => str_starts_with((string)($t['id'] ?? ''), 'versioniq.')));",
		"$ids = array_column($tools, 'id'); sort($ids);",
		"$provider = count(array_filter($svc->getProviders(), fn ($p) => $p instanceof \\OCA\\Versioniq\\Mcp\\VersioniqToolProvider));",
		"$readOnly = array_reduce($tools, fn ($ok, $t) => $ok && ($t['readOnlyHint'] ?? false) === true, true);",
		"echo json_encode(['ids' => $ids, 'provider' => $provider, 'readOnly' => $readOnly]);",
	].join(" ");
	const { code, stdout, stderr } = await execInInstance(["php", "-r", script]);
	expect(code, stderr).toBe(0);
	return JSON.parse(stdout.match(/\{[\s\S]*\}\s*$/)?.[0] ?? "{}");
}

test.describe("MCP tools through OpenRegister", () => {
	test.beforeEach(async () => {
		const enabled = await occ("app:list", "--enabled", "--output=json");
		test.skip(!/"openregister"\s*:/.test(enabled), "OpenRegister is not enabled on this instance");
	});

	// @e2e admin-mcp-assistant::openregister-discovers-the-provider
	test("OpenRegister's McpToolsService resolves the provider and lists five read-only tools for an admin", async () => {
		const asAdmin = await discovered(NC_ADMIN_USER);
		expect(asAdmin.provider, "VersioniqToolProvider among the providers").toBe(1);
		expect(asAdmin.ids).toEqual(EXPECTED);
		expect(asAdmin.readOnly).toBe(true);
	});

	// @e2e admin-mcp-assistant::no-tools-without-an-admin
	test("without an admin session the provider lists no tools", async () => {
		const asNobody = await discovered("");
		expect(asNobody.ids).toEqual([]);
	});
});
