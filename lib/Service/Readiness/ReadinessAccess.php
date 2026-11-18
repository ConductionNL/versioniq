<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Readiness;

use OCP\BackgroundJob\IJobList;

/**
 * What the readiness endpoints need: the last report, and the job list a
 * new check is queued on (inventory-upgrade-readiness D4).
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 * @psalm-api
 */
final class ReadinessAccess {
	public function __construct(
		public readonly ReadinessResultStore $readinessResultStore,
		public readonly IJobList $jobList,
	) {
	}
}
