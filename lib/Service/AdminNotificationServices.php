<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Notification\IManager;

/**
 * What a class needs to notify the admin group: the notification manager,
 * the group manager that lists the admins, the clock that stamps the
 * notification and the URL generator for its link.
 *
 * @psalm-api
 */
final class AdminNotificationServices {
	public function __construct(
		public readonly IManager $notificationManager,
		public readonly IGroupManager $groupManager,
		public readonly ITimeFactory $timeFactory,
		public readonly IURLGenerator $urlGenerator,
	) {
	}
}
