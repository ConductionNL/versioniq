<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/*
 * OpenRegister IMcpToolProvider stub (admin-mcp-assistant, design D1).
 *
 * Mirrors openregister lib/Mcp/IMcpToolProvider.php on development: the three
 * methods with their names and types, verbatim. The real interface ships in
 * OpenRegister; the unit bootstrap loads this stub only when it is absent, and
 * psalm reads it so it sees the contract the provider implements.
 */

namespace OCA\OpenRegister\Mcp;

/**
 * A per-app provider of MCP tools that OpenRegister discovers and serves.
 */
interface IMcpToolProvider {
	/**
	 * The app id that owns these tools; every tool id starts with it.
	 *
	 * @return string
	 */
	public function getAppId(): string;

	/**
	 * The tool descriptors: `{id, name, description, inputSchema, scope?, readOnlyHint?, destructiveHint?, idempotentHint?}`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getTools(): array;

	/**
	 * Run one tool for the current session user.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array $arguments The arguments, keyed by name (OpenRegister documents array<string, mixed>; a stub docblock type would bind as the signature).
	 *
	 * @return array<string, mixed>
	 */
	public function invokeTool(string $toolId, array $arguments): array;
}
