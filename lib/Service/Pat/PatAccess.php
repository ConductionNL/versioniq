<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Pat;

use OCP\IUserSession;

/**
 * Finds the personal access token a request to a forge may use: the
 * resolver picks the token for the acting user, the manager decrypts it.
 *
 * @psalm-api
 */
final class PatAccess {
	public function __construct(
		public readonly PatResolver $patResolver,
		public readonly PatManager $patManager,
		public readonly IUserSession $userSession,
	) {
	}
}
