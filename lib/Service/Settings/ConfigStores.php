<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Settings;

use OCP\IAppConfig;
use OCP\IConfig;

/**
 * The two Nextcloud configuration stores: the system and untyped app values
 * of IConfig, and the typed app values of IAppConfig.
 *
 * @psalm-api
 */
final class ConfigStores {
	public function __construct(
		public readonly IConfig $config,
		public readonly IAppConfig $appConfig,
	) {
	}
}
