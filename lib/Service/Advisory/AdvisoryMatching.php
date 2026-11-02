<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

/**
 * What advisory correlation matches versions with: the Nextcloud advisory
 * feed, the branch-aware range check that finds the patched version, and the
 * running server version.
 *
 * @psalm-api
 */
final class AdvisoryMatching {
	public function __construct(
		public readonly NextcloudAdvisoryFeed $advisoryFeed,
		public readonly BranchAwareRange $branchRange,
		public readonly ServerVersionProvider $serverVersion,
	) {
	}
}
