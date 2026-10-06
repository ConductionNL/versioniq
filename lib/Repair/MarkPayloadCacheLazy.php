<?php

/**
 * Repair step that moves the App Store payload cache out of Nextcloud's
 * per-request app-config preload.
 *
 * @category Repair
 * @package  OCA\Versioniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);


namespace OCA\Versioniq\Repair;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Marks every existing `appstore.payload.*` / `appstore.payload_ts.*` row lazy.
 *
 * WHY. Until this release {@see AppStoreSource} wrote its payload cache as
 * EAGER app config, and Nextcloud loads every eager row of every app on every
 * request. One payload per managed app, ~4 MB for mail alone: measured on a
 * dev instance 2026-10-06, 1,660 rows / 36 MB took ~300 ms of a ~450 ms
 * `status.php`, slowing every page of the instance, not just this app's.
 *
 * The code now writes these keys lazy, and a write flips an existing row's
 * flag. But a row is only rewritten when its app is listed again after the
 * TTL, so a payload for an app nobody looks at would stay eager forever. This
 * step flips them all at once.
 *
 * Idempotent: `updateLazy()` on a row that is already lazy changes nothing, so
 * a fresh install and a second run both pass through without effect. Never
 * raises: a cache row is not worth an aborted upgrade.
 *
 * @psalm-suppress UnusedClass Nextcloud instantiates repair steps from the
 *  `<repair-steps>` block in appinfo/info.xml, which psalm does not read.
 */
class MarkPayloadCacheLazy implements IRepairStep {

	/**
	 * @param IAppConfig $appConfig The app config store.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as shown by `occ upgrade`.
	 *
	 * @return string The name.
	 *
	 * @spec exclude Performance repair of a cache row's storage flag; no
	 *  capability spec covers how the payload cache is stored.
	 */
	public function getName(): string {
		return 'Mark the App Store payload cache as lazy app config';
	}//end getName()

	/**
	 * Flip each payload cache key to lazy.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec exclude Performance repair of a cache row's storage flag; no
	 *  capability spec covers how the payload cache is stored.
	 */
	public function run(IOutput $output): void {
		try {
			$keys = $this->appConfig->getKeys(Application::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[MarkPayloadCacheLazy] Could not list app config keys: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]
			);
			$output->warning('Could not list app config keys: ' . $e->getMessage());
			return;
		}

		$flipped = 0;
		foreach ($keys as $key) {
			if (str_starts_with($key, AppStoreSource::PAYLOAD_CACHE_PREFIX) === false
				&& str_starts_with($key, AppStoreSource::PAYLOAD_CACHE_TS_PREFIX) === false
			) {
				continue;
			}

			try {
				if ($this->appConfig->updateLazy(Application::APP_ID, $key, true) === true) {
					$flipped++;
				}
			} catch (Throwable $e) {
				// Reported, not raised — see the class docblock.
				$this->logger->warning(
					'[MarkPayloadCacheLazy] Could not mark ' . $key . ' lazy: ' . $e->getMessage(),
					['app' => Application::APP_ID, 'exception' => $e]
				);
			}
		}

		$output->info('Marked ' . $flipped . ' App Store payload cache keys lazy');

	}//end run()
}//end class
