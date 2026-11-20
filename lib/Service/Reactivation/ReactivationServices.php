<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Reactivation;

use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * What {@see ReactivationService} reads the app, the server and the settings
 * with: the app manager reports and enables the app, the provider the
 * running server version, the app config the automatic re-enabling switch,
 * and the pending-updates snapshot the newest compatible version.
 *
 * @psalm-api
 */
final class ReactivationServices {
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly ServerVersionProvider $serverVersion,
		public readonly IAppConfig $config,
		public readonly ?AvailabilityResultStore $availability = null,
	) {
	}
}
