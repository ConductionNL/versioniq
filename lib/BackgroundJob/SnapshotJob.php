<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Takes the daily snapshot of every managed app's version
 * (install-bulk-rollback design D2). A failure is logged and the next run
 * tries again.
 *
 * @psalm-api
 */
class SnapshotJob extends TimedJob {
	public const INTERVAL_SECONDS = 24 * 60 * 60;

	private LoggerInterface $logger;

	public function __construct(
		JobRuntime $runtime,
		private SnapshotTaker $taker,
	) {
		parent::__construct($runtime->time);
		$this->logger = $runtime->logger;
		$this->setInterval(self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	protected function run($argument): void {
		AuditOrigin::during(AuditOrigin::JOB, function (): void {
			try {
				$this->taker->take('daily', 'system');
			} catch (\Throwable $error) {
				$this->logger->warning('SnapshotJob: the daily snapshot failed', ['message' => $error->getMessage()]);
			}
		});
	}
}
