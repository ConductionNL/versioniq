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

use OCA\Versioniq\Kernel\Contracts\AdvisoryDigestNotifierInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryNotifierInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryResultStoreInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryServiceInterface;
use OCA\Versioniq\Service\Advisory\AdvisoryExposureTracker;
use OCA\Versioniq\Service\Advisory\AdvisoryTriageStore;

/**
 * The four steps of one advisory refresh, in the order the job runs them:
 * correlate every app, store the snapshot, notify about new advisories and
 * send the weekly digest when it is due.
 *
 * @psalm-api
 */
final class AdvisoryRefreshSteps {
	public function __construct(
		public readonly AdvisoryServiceInterface $advisoryService,
		public readonly AdvisoryResultStoreInterface $resultStore,
		public readonly AdvisoryNotifierInterface $advisoryNotifier,
		public readonly AdvisoryDigestNotifierInterface $digestNotifier,
		public readonly ?AdvisoryExposureTracker $exposureTracker = null,
		// Optional: the advisory triage, stamped and pruned after each save (advisories-triage).
		public readonly ?AdvisoryTriageStore $triageStore = null,
	) {
	}
}
