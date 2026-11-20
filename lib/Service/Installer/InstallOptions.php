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
 * recorded SHA-256, acknowledging a downgrade, forcing a version past its
 * declared maximum (`app_install_overwrite`), where the request runs (`web`
 * or `cli`), which decides the update checks a dry run runs, and the SHA-256
 * another instance recorded for the version a promotion installs.
 *
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
 */
final class InstallOptions {
	public function __construct(
		public readonly bool $includeDebug,
		public readonly bool $dryRun,
		public readonly bool $acceptNewSha,
		public readonly bool $allowDowngrade,
		public readonly bool $forceIncompatible = false,
		public readonly string $checkContext = CheckRunner::CONTEXT_WEB,
		public readonly ?string $expectedSha = null,
	) {
	}
}
