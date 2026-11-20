<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Instance;

/**
 * How one promotion runs: the acknowledgements the admin gave, the version
 * they saw and whether only to check (install-multi-instance-promotion design D6, D7).
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
 */
final class PromotionOptions {
	/**
	 * @param bool $allowDowngrade Acknowledges that the promotion installs an older version
	 * @param bool $overrideMinDays Promotes inside the waiting period; written into the audit row
	 * @param ?string $plannedVersion The version the admin saw, or null to take what runs now
	 * @param bool $dryRun Checks the promotion without installing anything
	 */
	public function __construct(
		public readonly bool $allowDowngrade = false,
		public readonly bool $overrideMinDays = false,
		public readonly ?string $plannedVersion = null,
		public readonly bool $dryRun = false,
	) {
	}
}
