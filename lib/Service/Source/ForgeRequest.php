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
 * One request to a forge: the forge, the repository it is about (which picks
 * the token) and the URL.
 *
 * @psalm-api
 */
final class ForgeRequest {
	public function __construct(
		public readonly Forge $forge,
		public readonly string $ownerRepo,
		public readonly string $url,
	) {
	}
}
