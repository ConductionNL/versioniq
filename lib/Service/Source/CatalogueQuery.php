<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use Closure;

/**
 * One paged App Store catalogue search: the app looked for, the endpoint of
 * each page, and whether an empty or non-array body ends the whole lookup
 * (the filtered endpoint) or only skips the page (the platform endpoint).
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class CatalogueQuery {
	public bool $stopOnBadBody = false;

	/**
	 * @param Closure(int): string $endpoint
	 */
	public function __construct(
		public readonly string $appId,
		public readonly Closure $endpoint,
	) {
	}
}
