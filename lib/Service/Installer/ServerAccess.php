<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Installer;

use OCA\Versioniq\Service\Settings\ConfigStores;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The Nextcloud server an install changes: its app manager, its
 * configuration and the dispatcher that announces an update.
 *
 * @psalm-api
 */
final class ServerAccess {
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly ConfigStores $configs,
		public readonly IEventDispatcher $eventDispatcher,
	) {
	}
}
