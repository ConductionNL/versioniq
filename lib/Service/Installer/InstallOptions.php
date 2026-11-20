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

use OCA\Versioniq\Service\Checks\CheckRunner;

/**
 * The flags of one install request: debug output, dry run, accepting a new
 * recorded SHA-256, acknowledging a downgrade, and where the request runs
 * (`web` or `cli`), which decides the update checks a dry run runs.
 *
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
final class InstallOptions {
	public function __construct(
		public readonly bool $includeDebug,
		public readonly bool $dryRun,
		public readonly bool $acceptNewSha,
		public readonly bool $allowDowngrade,
		public readonly string $checkContext = CheckRunner::CONTEXT_WEB,
	) {
	}
}
