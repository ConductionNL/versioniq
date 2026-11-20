<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * The public surface of InstanceSettings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InstanceSettingsInterface {
	/**
	 * The current values, the overrides as stored ('' when unset) and the
	 * defaults they fall back to, so the page can show both.
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @return array{auditRetentionDays: int, auditRetentionMinDays: int, auditRetentionMaxDays: int, artifactCacheKeep: int, artifactCacheKeepMax: int, appStoreApiBase: string, appStoreApiDefault: string, githubApiBase: string, githubApiDefault: string, githubWebBase: string, githubWebDefault: string, advisoryFeedUrl: string, advisoryFeedDefault: string, eolFeedUrl: string, eolFeedOff: bool, eolFeedDefault: string, ncscFeedUrl: string, ncscFeedDefault: string, maxLinesBehind: ?int, maxLinesBehindMax: int, githubSearchEnabled: bool, advisoryFeedSource: string, advisoryFeedCopiedAt: ?int, advisoryFeedCopyMaxAgeDays: int, crlUrl: string, crlUrlDefault: string, disableRevoked: bool}
	 */
	public function read(): array;

	/**
	 * Applies the given fields; an omitted field is left alone and a blank URL
	 * clears its override.
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @param array<string, string> $fields
	 * @return list<string> The names of the fields that were applied
	 * @throws InvalidArgumentException when any field is out of range or not an acceptable URL
	 */
	public function update(array $fields): array;

	/**
	 * The admin's lag limit in release lines, or null when the check is off.
	 *
	 * @spec openspec/specs/pending-updates/spec.md#requirement-an-admin-sets-how-far-an-app-may-fall-behind
	 */
	public function maxLinesBehind(): ?int;
}
