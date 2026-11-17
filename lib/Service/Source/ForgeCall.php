<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Source;

/**
 * One request to a forge: which forge, which endpoint, and the token to send,
 * or null for an anonymous request.
 */
final class ForgeCall {
	public function __construct(
		public readonly Forge $forge,
		public readonly string $endpoint,
		public readonly ?string $token = null,
	) {
	}
}
