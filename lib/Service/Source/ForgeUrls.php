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

/**
 * The three addresses of a forge: its API base, its web base and the page
 * where a user creates an access token.
 *
 * @spec openspec/specs/external-sources/spec.md
 * @psalm-api
 */
final class ForgeUrls {
	public function __construct(
		public readonly string $api,
		public readonly string $web,
		public readonly string $tokenCreate,
	) {
	}
}
