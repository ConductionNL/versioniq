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
 * The app folder after the files were replaced, the backup kept until
 * finalize succeeds, and the app info read from the new files.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class InstalledFolder {
	/** @var array<string, mixed> */
	public array $info = [];

	public function __construct(
		public readonly string $appPath,
		public readonly ?string $backup,
	) {
	}
}
