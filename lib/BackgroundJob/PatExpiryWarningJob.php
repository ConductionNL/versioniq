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

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatExpiryNotifier;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily sweep of every stored PAT: for each token with a known `expiresAt`,
 * notifies the owner once per crossed threshold (14 days, 3 days, expiry).
 * Tokens without a known expiry are left alone — never probed, never
 * notified. Each token is processed independently so one bad row (decode
 * failure, missing owner, forge lookup error) never blocks the rest of the
 * sweep.
 *
 * @psalm-api
 */
class PatExpiryWarningJob extends TimedJob {
	/** Sweep once every 24 hours. */
	private const INTERVAL_SECONDS = 24 * 60 * 60;

	private LoggerInterface $logger;

	public function __construct(
		JobRuntime $runtime,
		private PatMapper $patMapper,
		private PatExpiryEvaluator $evaluator,
		private PatExpiryNotifier $notifier,
	) {
		parent::__construct($runtime->time);
		$this->logger = $runtime->logger;
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		foreach ($this->patMapper->findAll() as $pat) {
			try {
				$this->processPat($pat);
			} catch (\Throwable $error) {
				$this->logger->warning('PatExpiryWarningJob: failed to process a token', [
					'patId' => $pat->getId(),
					'message' => $error->getMessage(),
				]);
			}
		}
	}

	/**
	 * Evaluates and, if needed, notifies for a single token; see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	private function processPat(Pat $pat): void {
		$expiresAt = $pat->getExpiresAt();
		if ($expiresAt === null) {
			// Unknown expiry (fine-grained/Codeberg tokens the API didn't
			// disclose) MUST NOT be probed or notified.
			return;
		}

		$threshold = $this->evaluator->highestCrossedThreshold($expiresAt);
		if ($threshold === null) {
			return;
		}
		if ($pat->hasWarnedThreshold($threshold)) {
			return;
		}

		$daysRemaining = $this->evaluator->daysRemaining($expiresAt);
		$notified = $this->notifier->notify($pat, $threshold, $daysRemaining);
		if (!$notified) {
			// Notification failed — leave the ledger untouched so the next
			// run retries this threshold instead of silently skipping it.
			return;
		}

		$pat->addWarnedThreshold($threshold);
		$this->patMapper->update($pat);
	}
}
