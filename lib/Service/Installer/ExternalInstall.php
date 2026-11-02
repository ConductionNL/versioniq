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
 * One external install once its archive is verified and staged: the run, the
 * integrity outcome, the staged release and the migrations a downgrade orphans.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class ExternalInstall {
	/**
	 * @param list<string>|null $orphanedMigrations
	 */
	public function __construct(
		public readonly ExternalInstallRun $run,
		public readonly ArchiveCheck $check,
		public readonly StagedRelease $staged,
		public readonly ?array $orphanedMigrations,
	) {
	}
}
