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

use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\LibraryAdvisoryCheck;
use OCA\Versioniq\Service\Advisory\LibraryAdvisorySweep;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily check of the PHP libraries bundled in every enabled app against OSV.
 *
 * Does nothing while the admin has not switched the check on: it sends
 * library names and versions out. Separate from {@see AdvisoryRefreshJob} so
 * a slow OSV call can never cut the app and server sweep short, and daily
 * because bundled libraries only change when an app is updated.
 *
 * It reads and stores only; it never changes a version.
 *
 * @psalm-api
 */
class LibraryAdvisoryJob extends TimedJob {
	public const INTERVAL_SECONDS = 86400;

	/** Wall-clock ceiling for the OSV calls of one run. */
	public const BUDGET_SECONDS = LibraryAdvisorySweep::BUDGET_SECONDS;

	/** Ceiling on vulnerability detail calls per run. */
	public const MAX_DETAIL_CALLS = LibraryAdvisorySweep::MAX_DETAIL_CALLS;

	public function __construct(
		ITimeFactory $time,
		private AdvisorySettingsStore $settings,
		private LibraryAdvisoryCheck $check,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		if (!$this->settings->isLibraryCheckEnabled()) {
			return;
		}

		try {
			$this->checkAll();
		} catch (\Throwable $error) {
			$this->logger->error('LibraryAdvisoryJob: check failed', ['message' => $error->getMessage()]);
		}
	}

	/**
	 * Scans every enabled app, asks OSV once for all distinct libraries, and
	 * stores the result per app (see {@see LibraryAdvisoryCheck}).
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 */
	public function checkAll(): void {
		$this->check->checkAll($this->time->getTime());
	}
}
