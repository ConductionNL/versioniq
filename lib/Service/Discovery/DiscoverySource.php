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
 * Where a discovery hit comes from and whether it can be installed from
 * there: the provider, the source binding an install would use, and the
 * reason when it cannot.
 *
 * @spec openspec/specs/app-discovery/spec.md
 * @psalm-api
 */
final class DiscoverySource {
	/**
	 * @param array<string, mixed> $binding
	 */
	public function __construct(
		public readonly string $providerId,
		public readonly array $binding,
		public readonly bool $installable,
		public readonly ?string $installableReason,
	) {
	}
}
