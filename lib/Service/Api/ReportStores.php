<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Api;

use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\LibraryAdvisoryStore;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Settings\InstanceSettings;

/**
 * The stored reports the API serves: the advisory snapshot and its settings,
 * the pending-updates snapshot and the instance settings with its lag limit.
 *
 * @psalm-api
 */
final class ReportStores {
	public function __construct(
		public readonly AdvisoryResultStore $advisoryResultStore,
		public readonly AdvisorySettingsStore $advisorySettingsStore,
		// Optional: the pending-updates snapshot (inventory-pending-updates).
		public readonly ?AvailabilityResultStore $availabilityResultStore = null,
		public readonly ?InstanceSettings $instanceSettings = null,
		// Optional: the bundled-library snapshot (advisories-bundled-libraries).
		public readonly ?LibraryAdvisoryStore $libraryAdvisoryStore = null,
	) {
	}
}
