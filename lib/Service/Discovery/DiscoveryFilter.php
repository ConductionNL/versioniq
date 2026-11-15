<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Discovery;

/**
 * Which providers a discovery search runs and whether it keeps only
 * installed apps.
 *
 * @spec openspec/specs/app-discovery/spec.md
 */
final class DiscoveryFilter {
	/**
	 * @param list<string>|null $sourceIds Provider ids to run; null or empty runs every enabled provider.
	 */
	public function __construct(
		public readonly ?array $sourceIds = null,
		public readonly bool $installedOnly = false,
	) {
	}
}
