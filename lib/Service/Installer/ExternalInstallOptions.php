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
 * The flags of one external install: whether it is a dry run, whether a
 * new SHA-256 may replace the one recorded for the version, and where the
 * request runs, which decides the update checks a dry run runs.
 *
 * @spec openspec/specs/external-sources/spec.md
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
final class ExternalInstallOptions {
	public function __construct(
		public readonly bool $dryRun,
		public readonly bool $acceptNewSha,
		public readonly string $checkContext = CheckRunner::CONTEXT_WEB,
	) {
	}
}
