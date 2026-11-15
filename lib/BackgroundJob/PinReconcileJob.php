<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Service\Pin\PinStore;
use OCP\App\IAppManager;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily safety net for drift detection: walks every pin and compares it
 * against the live installed version via `IAppManager`, catching updates
 * that the {@see \OCA\Versioniq\Listener\AppUpdatedListener} event-driven
 * path can miss (events dispatched while Versioniq was disabled, manual
 * file replacement, restored backups); see "Drift detection" (reconciliation
 * path).
 *
 * Registered via `<background-jobs>` in `appinfo/info.xml` — there is no
 * `IRegistrationContext::registerJob()` API for `TimedJob`s in this
 * Nextcloud version, only for `IJob`/`QueuedJob` via the coordinator;
 * TimedJobs must be declared in info.xml.
 *
 * @psalm-api
 */
class PinReconcileJob extends TimedJob {
	private const INTERVAL_SECONDS = 24 * 60 * 60;

	private LoggerInterface $logger;

	public function __construct(
		JobRuntime $runtime,
		private PinStore $pinStore,
		private IAppManager $appManager,
		private PinDriftHandler $driftHandler,
	) {
		parent::__construct($runtime->time);
		$this->logger = $runtime->logger;
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		foreach (array_keys($this->pinStore->all()) as $appId) {
			try {
				$installedVersion = $this->appManager->getAppVersion($appId, false);
				if ($installedVersion === '') {
					continue;
				}
				$this->driftHandler->handle($appId, $installedVersion);
			} catch (\Throwable $error) {
				$this->logger->warning('PinReconcileJob: failed to reconcile a pin', [
					'appId' => $appId,
					'message' => $error->getMessage(),
				]);
			}
		}
	}
}
