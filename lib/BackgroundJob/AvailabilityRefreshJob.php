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

use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every six hours, works out which installed apps have a newer version and
 * stores the answer for GET /api/updates and `occ versioniq:updates`
 * (inventory-pending-updates, design D1).
 *
 * @spec openspec/changes/inventory-pending-updates/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
 */
class AvailabilityRefreshJob extends TimedJob {
	public const SWEEP_BUDGET_SECONDS = 600.0;

	private const INTERVAL_SECONDS = 6 * 3600;

	public function __construct(
		ITimeFactory $time,
		private AvailabilityService $availabilityService,
		private AvailabilityResultStore $resultStore,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @spec openspec/changes/inventory-pending-updates/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$updates = $this->availabilityService->sweep(self::SWEEP_BUDGET_SECONDS);
			$this->resultStore->save($updates, $this->time->getTime());

			$unreached = count(array_filter($updates, static fn (array $entry): bool => ($entry['error'] ?? null) !== null));
			if ($unreached > 0) {
				$this->logger->warning('AvailabilityRefreshJob: some apps could not be checked', [
					'unreached' => $unreached,
					'total' => count($updates),
				]);
			}
		} catch (\Throwable $error) {
			$this->logger->error('AvailabilityRefreshJob: the update check failed; the previous snapshot is kept', ['message' => $error->getMessage()]);
		}
	}
}
