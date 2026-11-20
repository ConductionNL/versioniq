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
 * The app a backup is made of: its id, its folder, and the version and
 * enabled state it had before the install (safety-rollback-backup-and-preflight D3).
 *
 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
 */
final class BackupSource {
	public function __construct(
		public readonly string $appId,
		public readonly string $appPath,
		public readonly string $version,
		public readonly string $enabled,
	) {
	}
}
