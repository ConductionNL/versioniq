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

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * What every versioniq timed job needs besides its own work: the clock its
 * TimedJob parent schedules with, and the logger a failed item is reported to.
 *
 * @psalm-api
 */
final class JobRuntime {
	public function __construct(
		public readonly ITimeFactory $time,
		public readonly LoggerInterface $logger,
	) {
	}
}
