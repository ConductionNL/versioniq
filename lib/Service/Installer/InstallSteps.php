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

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Source\TrustedSourceList;

/**
 * The checks and closing steps around an external install: the trusted
 * source check, the migration diff, the finalizer and the audit log.
 *
 * @psalm-api
 */
final class InstallSteps {
	public function __construct(
		public readonly TrustedSourceList $trustedSources,
		public readonly MigrationDiffer $migrationDiffer,
		public readonly InstallFinalizer $finalizer,
		public readonly AuditLogger $auditLogger,
	) {
	}
}
