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
 * The flags of one external install: whether it is a dry run, and whether a
 * new SHA-256 may replace the one recorded for the version.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class ExternalInstallOptions {
	public function __construct(
		public readonly bool $dryRun,
		public readonly bool $acceptNewSha,
	) {
	}
}
