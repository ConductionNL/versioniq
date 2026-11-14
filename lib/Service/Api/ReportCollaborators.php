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

use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Advisory\LibraryAdvisoryStore;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;

/**
 * What the read-only report endpoints draw on: the stored reports, the
 * discovery search, the audit log and the bundled-library snapshot.
 *
 * @psalm-api
 */
final class ReportCollaborators {
	public function __construct(
		public readonly ReportStores $stores,
		public readonly DiscoveryAggregator $discoveryAggregator,
		public readonly AuditEntryMapper $auditEntryMapper,
		// Optional: the bundled-library snapshot (advisories-bundled-libraries).
		public readonly ?LibraryAdvisoryStore $libraryAdvisoryStore = null,
	) {
	}
}
