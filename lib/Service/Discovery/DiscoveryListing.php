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
 * How a discovery hit presents its app: the display name, the summary, and
 * the icon and homepage addresses when the source has them.
 *
 * @spec openspec/specs/app-discovery/spec.md
 * @psalm-api
 */
final class DiscoveryListing {
	public function __construct(
		public readonly string $name,
		public readonly string $summary,
		public readonly ?string $iconUrl,
		public readonly ?string $homepageUrl,
		public readonly ?string $publisher = null,
		public readonly bool $featured = false,
	) {
	}
}
