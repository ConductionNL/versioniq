<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\AppInfo;

use OCA\Versioniq\Listener\AppUpdatedListener;
use OCA\Versioniq\Listener\UserDeletedListener;
use OCA\Versioniq\Middleware\ApiContractMiddleware;
use OCA\Versioniq\Notification\Notifier;
use OCP\App\Events\AppUpdateEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	/**
	 * This app's Nextcloud app id, and the namespace every IAppConfig /
	 * IConfig user value is stored under.
	 *
	 * Renamed from `app_versions` with the fleet. Nextcloud has no in-place
	 * app-id upgrade, so the rows written under the old id are carried across
	 * by the repair steps in {@see \OCA\Versioniq\Repair\MigrateAppConfigKeys}
	 * and {@see \OCA\Versioniq\Repair\MigrateUserPreferences}, which hold the
	 * old id in their own OLD_APP_ID constants.
	 */
	public const APP_ID = 'versioniq';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(AppUpdateEvent::class, AppUpdatedListener::class);
		$context->registerNotifierService(Notifier::class);

		// API-Version header and problem details on the JSON API (spec api-contract).
		$context->registerMiddleware(ApiContractMiddleware::class);
	}

	public function boot(IBootContext $context): void {
	}
}
