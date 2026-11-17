<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\Audit\ExternalChangeReconciler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly comparison of every app and the server with what Versioniq last saw,
 * for the changes no event reported: files replaced by hand, a restored
 * backup, an update while Versioniq was off, or a disable during a server
 * upgrade before Versioniq's listener was loaded.
 *
 * The first run only stores the baseline; the comparison itself is
 * {@see ExternalChangeReconciler}. Separate from
 * {@see PinReconcileJob}, which stays a daily pass over pinned apps.
 *
 * @psalm-api
 */
class ExternalChangeReconcileJob extends TimedJob {
	public const INTERVAL_SECONDS = 3600;

	public function __construct(
		ITimeFactory $time,
		private ExternalChangeReconciler $reconciler,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$this->reconcile();
		} catch (\Throwable $error) {
			$this->logger->error('ExternalChangeReconcileJob: check failed', ['message' => $error->getMessage()]);
		}
	}

	/**
	 * Compares, records what changed, and stores what is now seen.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function reconcile(): void {
		$this->reconciler->reconcile();
	}
}
