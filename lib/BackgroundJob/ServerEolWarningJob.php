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

use OCA\Versioniq\Service\Lifecycle\ServerEolWarner;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: warn the admins before the running server major leaves support.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
class ServerEolWarningJob extends TimedJob {
	private const INTERVAL_SECONDS = 24 * 60 * 60;

	private LoggerInterface $logger;

	public function __construct(
		JobRuntime $runtime,
		private ServerEolWarner $warner,
	) {
		parent::__construct($runtime->time);
		$this->logger = $runtime->logger;
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
	 */
	protected function run($argument): void {
		try {
			$this->warner->warnIfDue();
		} catch (\Throwable $error) {
			$this->logger->warning('ServerEolWarningJob: the end-of-life check failed', ['message' => $error->getMessage()]);
		}
	}
}
