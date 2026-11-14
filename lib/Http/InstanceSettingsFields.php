<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Http;

/**
 * The request fields of PUT /api/instance-settings; a field left out (null)
 * leaves that setting alone.
 *
 * RequestFields reads the properties by reflection.
 *
 * @psalm-api
 */
final class InstanceSettingsFields extends RequestFields {
	/** @var string|null Days of history to keep, 30 to 3650 */
	public ?string $auditRetentionDays = null;

	/** @var string|null Archives kept per app, 0 to 20 (0 turns the cache off) */
	public ?string $artifactCacheKeep = null;

	/** @var string|null App Store API base URL; blank uses the public store */
	public ?string $appStoreApiBase = null;

	/** @var string|null GitHub API base URL (https); blank uses api.github.com */
	public ?string $githubApiBase = null;

	/** @var string|null GitHub web base URL (https); blank uses github.com */
	public ?string $githubWebBase = null;

	/** @var string|null Advisory feed URL; blank uses the published Nextcloud feed */
	public ?string $advisoryFeedUrl = null;

	/** @var string|null Release lines an app may fall behind, 0 to 10; blank turns the check off */
	public ?string $maxLinesBehind = null;

	public ?string $notifyUsersOnUpdate = null;

	/**
	 * The fields that were sent, by name, in declaration order.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function sent(): array {
		return array_filter(get_object_vars($this), static fn (?string $value): bool => $value !== null);
	}
}
