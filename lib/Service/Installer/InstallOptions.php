<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

/**
 * The flags of one install request: debug output, dry run, accepting a new
 * recorded SHA-256 and acknowledging a downgrade.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class InstallOptions {
	public function __construct(
		public readonly bool $includeDebug,
		public readonly bool $dryRun,
		public readonly bool $acceptNewSha,
		public readonly bool $allowDowngrade,
	) {
	}
}
