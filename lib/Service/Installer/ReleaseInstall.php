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

use OCA\Versioniq\Service\Source\SourceInterface;

/**
 * One install run once its release is resolved: the run, the source driver,
 * the release metadata and the version the app reports after the install.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class ReleaseInstall {
	public string $appVersion = '';

	/**
	 * @param array<string, mixed> $release
	 */
	public function __construct(
		public readonly InstallRun $run,
		public readonly SourceInterface $source,
		public readonly array $release,
	) {
	}
}
