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
 * The temporary file an archive downloads to and the folder it extracts into.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class TempArchive {
	public function __construct(
		public readonly string $file,
		public readonly string $folder,
	) {
	}
}
