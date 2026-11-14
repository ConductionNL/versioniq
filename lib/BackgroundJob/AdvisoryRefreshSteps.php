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

use OCA\Versioniq\Service\Advisory\AdvisoryDigestNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryExposureTracker;
use OCA\Versioniq\Service\Advisory\AdvisoryNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;

/**
 * The four steps of one advisory refresh, in the order the job runs them:
 * correlate every app, store the snapshot, notify about new advisories and
 * send the weekly digest when it is due.
 *
 * @psalm-api
 */
final class AdvisoryRefreshSteps {
	public function __construct(
		public readonly AdvisoryService $advisoryService,
		public readonly AdvisoryResultStore $resultStore,
		public readonly AdvisoryNotifier $advisoryNotifier,
		public readonly AdvisoryDigestNotifier $digestNotifier,
		public readonly ?AdvisoryExposureTracker $exposureTracker = null,
	) {
	}
}
