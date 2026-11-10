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
 * What {@see InstallFinalizer::finalize()} completes: the extracted app folder,
 * its parsed `appinfo/info.xml`, the `enabled` value to write and the source
 * binding id recorded with the last-known-good version.
 */
final class InstallFinalization {
	/** The `enabled` app config value written after the install. */
	public string $enabled = '';

	/** The source binding id (e.g. `appstore`, `github:owner/repo`) the version came from. */
	public string $sourceId = 'appstore';

	/**
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml` for the just-extracted version.
	 */
	public function __construct(
		public readonly string $appPath,
		public readonly array $info,
	) {
	}
}
