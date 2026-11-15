<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Api;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Who calls the API and when: the session user, the group manager that says
 * whether that user is an administrator, and the clock that stamps the
 * changes they make.
 *
 * @psalm-api
 */
final class ApiCaller {
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly ITimeFactory $timeFactory,
	) {
	}
}
